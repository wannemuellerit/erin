<?php

namespace App\Http\Controllers\Integrations;

use App\Contracts\TicketingProvider;
use App\Enums\SupportTicketStatus;
use App\Events\SupportTicketMessageCreated;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Notifications\ActivityNotification;
use App\Services\Activity\ActivityRecorder;
use App\Services\Billing\IntegrationEventGuard;
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
        abort_unless(
            $signatures->isValid(
                $request->getContent(),
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
                'attachments' => $this->attachmentMetadata($article),
            ]);
            $ticket->update([
                'last_reply_at' => now(),
                'status' => $sender === 'customer'
                    ? SupportTicketStatus::Open
                    : SupportTicketStatus::WaitingForCustomer,
            ]);

            DB::afterCommit(static function () use ($message): void {
                SupportTicketMessageCreated::dispatch($message);
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
     * @return list<array<string, mixed>>|null
     */
    private function attachmentMetadata(array $article): ?array
    {
        $attachments = is_array($article['attachments'] ?? null) ? $article['attachments'] : [];
        $safe = [];
        foreach ($attachments as $attachment) {
            $safe[] = [
                'external_id' => is_array($attachment) ? ($attachment['id'] ?? null) : null,
                'filename' => is_array($attachment)
                    ? mb_substr((string) ($attachment['filename'] ?? 'Anhang'), 0, 255)
                    : 'Anhang',
                'size' => is_array($attachment) ? ($attachment['size'] ?? null) : null,
            ];
        }

        return $safe === [] ? null : $safe;
    }
}
