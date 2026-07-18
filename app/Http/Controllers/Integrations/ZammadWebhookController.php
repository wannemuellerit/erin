<?php

namespace App\Http\Controllers\Integrations;

use App\Contracts\TicketingProvider;
use App\Enums\SupportTicketStatus;
use App\Events\SupportTicketMessageCreated;
use App\Exceptions\SupportAttachmentLimitExceeded;
use App\Http\Controllers\Controller;
use App\Jobs\ImportZammadAttachment;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Notifications\ActivityNotification;
use App\Services\Activity\ActivityRecorder;
use App\Services\Billing\IntegrationEventGuard;
use App\Services\Ticketing\SupportAttachmentLimits;
use App\Services\Ticketing\ZammadWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ZammadWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TicketingProvider $provider,
        IntegrationEventGuard $guard,
        ActivityRecorder $activity,
        ZammadWebhookSignature $signatures,
    ): JsonResponse {
        abort_unless($provider->enabled(), Response::HTTP_SERVICE_UNAVAILABLE);
        $secret = (string) config('services.zammad.webhook_secret');
        abort_if($secret === '', Response::HTTP_SERVICE_UNAVAILABLE);
        $maxBytes = max(1, (int) config('services.zammad.webhook_max_bytes', 2 * 1024 * 1024));
        $declaredBytes = $request->header('Content-Length');
        abort_if(
            is_numeric($declaredBytes) && (int) $declaredBytes > $maxBytes,
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        );
        $rawPayload = $request->getContent();
        abort_if(
            strlen($rawPayload) > $maxBytes,
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        );
        abort_unless(
            $signatures->isValid(
                $rawPayload,
                (string) $request->header('X-Hub-Signature'),
                $secret,
            ),
            Response::HTTP_UNAUTHORIZED,
        );
        $delivery = (string) $request->header('X-Zammad-Delivery');
        abort_if($delivery === '', Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = $request->json()->all();
        abort_unless(is_array($payload['ticket'] ?? null), Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload['id'] = $delivery;
        $payload['type'] = 'zammad.ticket.updated';
        $ticketPayload = $payload['ticket'];
        $externalTicketId = $ticketPayload['id'] ?? null;
        abort_if($externalTicketId === null, Response::HTTP_UNPROCESSABLE_ENTITY);

        /** @var SupportTicket|null $ticket */
        $ticket = SupportTicket::query()
            ->where('external_system', 'zammad')
            ->where('external_id', (string) $externalTicketId)
            ->first();

        if ($ticket === null) {
            return response()->json(['accepted' => true, 'matched' => false], Response::HTTP_ACCEPTED);
        }

        $article = $this->latestArticle($provider, $ticketPayload);
        $guard->once('zammad', $payload, function () use (
            $ticket,
            $ticketPayload,
            $article,
            $activity,
        ): void {
            $ticket->update([
                'subject' => mb_substr((string) ($ticketPayload['title'] ?? $ticket->subject), 0, 180),
                'status' => $this->status($ticketPayload, $ticket->status),
                'sync_status' => 'synced',
                'sync_error' => null,
                'last_synced_at' => now(),
            ]);

            if (
                $article === null
                || (bool) ($article['internal'] ?? false)
                || ! isset($article['id'])
            ) {
                return;
            }

            $externalArticleId = (string) $article['id'];
            if (SupportTicketMessage::query()->where('external_article_id', $externalArticleId)->exists()) {
                return;
            }

            $sender = mb_strtolower((string) ($article['sender'] ?? 'agent'));
            $message = $ticket->messages()->create([
                'author_id' => $sender === 'customer' ? $ticket->requester_id : null,
                'external_article_id' => $externalArticleId,
                'source' => 'zammad',
                'delivery_status' => 'delivered',
                'delivered_at' => now(),
                'body' => $this->plainText((string) ($article['body'] ?? '')),
                'is_internal' => false,
                'attachments' => null,
            ]);
            $attachmentIds = $this->createAttachmentRecords($message, $article);
            $ticket->update([
                'last_reply_at' => now(),
                'status' => $sender === 'customer'
                    ? SupportTicketStatus::Open
                    : SupportTicketStatus::WaitingForCustomer,
            ]);

            DB::afterCommit(static function () use ($message, $attachmentIds): void {
                SupportTicketMessageCreated::dispatch($message);
                foreach ($attachmentIds as $attachmentId) {
                    ImportZammadAttachment::dispatch($attachmentId);
                }
            });
            $activity->record(
                'support.message_received',
                null,
                $ticket->company_id,
                $message,
                ['ticket_number' => $ticket->number],
                $ticket->requester,
                'shared',
            );

            if ($sender !== 'customer') {
                $ticket->requester->notify(new ActivityNotification([
                    'event' => 'support.ticket_replied',
                    'translations' => [
                        'de' => [
                            'title' => 'Antwort vom Erin-Support',
                            'message' => sprintf('Dein Ticket %s wurde beantwortet.', $ticket->number),
                        ],
                        'en' => [
                            'title' => 'Reply from Erin support',
                            'message' => sprintf('Your ticket %s has been answered.', $ticket->number),
                        ],
                    ],
                    'url' => route('support.index', ['ticket' => $ticket->getKey()]),
                    'ticket_id' => $ticket->getKey(),
                ]));
            }
        });

        return response()->json(['accepted' => true, 'matched' => true]);
    }

    /**
     * @param  array<string, mixed>  $ticket
     * @return array<string, mixed>|null
     */
    private function latestArticle(TicketingProvider $provider, array $ticket): ?array
    {
        if (is_array($ticket['article'] ?? null)) {
            return $ticket['article'];
        }

        $ids = is_array($ticket['article_ids'] ?? null) ? $ticket['article_ids'] : [];
        if ($ids === []) {
            return null;
        }

        return $provider->article((string) end($ids));
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private function status(array $ticket, SupportTicketStatus $fallback): SupportTicketStatus
    {
        $state = $ticket['state'] ?? $ticket['state_name'] ?? null;
        if (is_array($state)) {
            $state = $state['name'] ?? null;
        }
        $state = mb_strtolower((string) $state);

        return match (true) {
            str_contains($state, 'closed'), str_contains($state, 'geschlossen') => SupportTicketStatus::Closed,
            str_contains($state, 'resolved'), str_contains($state, 'gelöst') => SupportTicketStatus::Resolved,
            str_contains($state, 'pending'), str_contains($state, 'warten') => SupportTicketStatus::InProgress,
            $state === 'new', $state === 'open', $state === 'neu', $state === 'offen' => SupportTicketStatus::Open,
            default => $fallback,
        };
    }

    private function plainText(string $body): string
    {
        $body = preg_replace('/<br\s*\/?>/i', "\n", $body) ?? $body;
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($body);
    }

    /**
     * @param  array<string, mixed>  $article
     * @return list<int>
     */
    private function createAttachmentRecords(
        SupportTicketMessage $message,
        array $article,
    ): array {
        $attachments = is_array($article['attachments'] ?? null) ? $article['attachments'] : [];
        $attachmentIds = [];
        $limits = app(SupportAttachmentLimits::class);
        $declaredTotalBytes = 0;
        $acceptedAttachmentCount = 0;
        $seenExternalIds = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment) || ! is_scalar($attachment['id'] ?? null)) {
                continue;
            }

            $externalId = (string) $attachment['id'];
            if (isset($seenExternalIds[$externalId])) {
                continue;
            }

            try {
                $limits->assertFileCount($acceptedAttachmentCount + 1);
            } catch (SupportAttachmentLimitExceeded) {
                break;
            }

            $seenExternalIds[$externalId] = true;
            $acceptedAttachmentCount++;
            $declaredSize = is_numeric($attachment['size'] ?? null)
                ? (int) $attachment['size']
                : null;
            $isRejected = false;
            if ($declaredSize !== null) {
                try {
                    $limits->assertFileSize($declaredSize);
                    $limits->assertTotalSize($declaredTotalBytes + $declaredSize);
                    $declaredTotalBytes += $declaredSize;
                } catch (SupportAttachmentLimitExceeded) {
                    $isRejected = true;
                }
            }
            $record = $message->files()->firstOrCreate(
                ['external_id' => $externalId],
                [
                    'source' => 'zammad',
                    'original_name' => mb_substr(
                        basename(str_replace(
                            '\\',
                            '/',
                            (string) ($attachment['filename'] ?? 'Anhang'),
                        )),
                        0,
                        255,
                    ),
                    'mime_type' => $this->attachmentMimeType($attachment),
                    'size_bytes' => $declaredSize,
                    'scan_result' => $isRejected ? 'rejected' : 'pending',
                    'scan_completed_at' => $isRejected ? now() : null,
                ],
            );
            if ($record->scan_result === 'pending') {
                $attachmentIds[] = $record->getKey();
            }
        }

        return $attachmentIds;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function attachmentMimeType(array $attachment): ?string
    {
        $preferences = is_array($attachment['preferences'] ?? null)
            ? $attachment['preferences']
            : [];
        $value = $attachment['mime_type']
            ?? $attachment['mime-type']
            ?? $preferences['Mime-Type']
            ?? null;

        return is_string($value) ? mb_substr($value, 0, 160) : null;
    }
}
