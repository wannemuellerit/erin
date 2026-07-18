<?php

namespace App\Http\Controllers\Integrations;

use App\Contracts\TicketingProvider;
use App\Enums\SupportTicketStatus;
use App\Exceptions\SupportAttachmentLimitExceeded;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportZammadArticleReceipt;
use App\Services\Activity\ActivityRecorder;
use App\Services\Billing\IntegrationEventGuard;
use App\Services\Ticketing\SupportAttachmentLimits;
use App\Services\Ticketing\SupportWebhookOutboxDispatcher;
use App\Services\Ticketing\SupportWebhookOutboxRecorder;
use App\Services\Ticketing\SupportZammadWebhookInboxRecorder;
use App\Services\Ticketing\ZammadArticleReceiptRecorder;
use App\Services\Ticketing\ZammadMessageMarker;
use App\Services\Ticketing\ZammadWebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ZammadWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TicketingProvider $provider,
        IntegrationEventGuard $guard,
        ActivityRecorder $activity,
        ZammadMessageMarker $messageMarkers,
        SupportWebhookOutboxRecorder $outbox,
        SupportWebhookOutboxDispatcher $outboxDispatcher,
        SupportZammadWebhookInboxRecorder $inbox,
        ZammadWebhookSignature $signatures,
    ): JsonResponse {
        abort_unless($provider->enabled(), Response::HTTP_SERVICE_UNAVAILABLE);
        $secret = (string) config('services.zammad.webhook_secret');
        abort_if(strlen($secret) < 32, Response::HTTP_SERVICE_UNAVAILABLE);
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
        abort_unless(
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,254}\z/D', $delivery) === 1,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
        $payload = $request->json()->all();
        abort_unless(is_array($payload['ticket'] ?? null), Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload['id'] = 'zammad-body:'.hash('sha256', $rawPayload);
        $payload['type'] = 'zammad.ticket.updated';
        $ticketPayload = $payload['ticket'];
        $externalTicketId = $ticketPayload['id'] ?? null;
        abort_unless(
            $this->validZammadId($externalTicketId),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        /** @var SupportTicket|null $ticket */
        $ticket = SupportTicket::query()
            ->where('external_system', 'zammad')
            ->where('external_id', (string) $externalTicketId)
            ->first();

        if ($ticket === null) {
            $inbox->record(
                $delivery,
                (string) $externalTicketId,
                $rawPayload,
            );

            return response()->json(['accepted' => true, 'matched' => false], Response::HTTP_ACCEPTED);
        }

        $externalUpdatedAtMs = $this->externalUpdatedAtMs($ticketPayload['updated_at'] ?? null);
        abort_if($externalUpdatedAtMs === null, Response::HTTP_UNPROCESSABLE_ENTITY);
        abort_if(
            array_key_exists('title', $ticketPayload)
            && ! is_string($ticketPayload['title']),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
        $articles = $this->unseenArticles(
            $provider,
            $ticket,
            $ticketPayload,
            (string) $externalTicketId,
        );
        $guard->once('zammad', $payload, function () use (
            $ticket,
            $ticketPayload,
            $articles,
            $activity,
            $messageMarkers,
            $externalUpdatedAtMs,
            $outbox,
        ): void {
            /** @var SupportTicket $ticket */
            $ticket = SupportTicket::query()
                ->whereKey($ticket->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $shouldApplyState = $ticket->external_updated_at_ms === null
                || $externalUpdatedAtMs > $ticket->external_updated_at_ms;
            $ticketUpdates = [
                'sync_status' => 'synced',
                'sync_error' => null,
                'last_synced_at' => now(),
            ];
            if ($shouldApplyState) {
                $ticketUpdates = [
                    ...$ticketUpdates,
                    'subject' => mb_substr(
                        (string) ($ticketPayload['title'] ?? $ticket->subject),
                        0,
                        180,
                    ),
                    'status' => $this->status($ticketPayload, $ticket->status),
                    'external_updated_at_ms' => $externalUpdatedAtMs,
                ];
            }
            $ticket->update($ticketUpdates);

            $latestArticleSender = null;
            foreach ($articles as $normalizedArticle) {
                $article = $normalizedArticle['payload'];
                $externalArticleId = (string) $article['id'];
                $articleUpdatedAt = $normalizedArticle['updated_at'];
                if ($article['internal'] === true) {
                    app(ZammadArticleReceiptRecorder::class)->record(
                        $ticket->getKey(),
                        $externalArticleId,
                        true,
                        $articleUpdatedAt,
                    );

                    continue;
                }

                $articleSender = $normalizedArticle['sender'];
                if (
                    $articleSender === null
                    || $articleUpdatedAt === null
                    || ! is_string($article['body'] ?? null)
                ) {
                    throw new RuntimeException(
                        'Ein öffentlicher Zammad-Artikel ist nach der Validierung unvollständig.',
                    );
                }
                $latestArticleSender = $articleSender;
                $existingMessage = SupportTicketMessage::query()
                    ->where('external_article_id', $externalArticleId)
                    ->first(['support_ticket_id']);
                if ($existingMessage !== null) {
                    if ($existingMessage->support_ticket_id !== $ticket->getKey()) {
                        throw new RuntimeException(
                            'Ein Zammad-Artikel wurde einem anderen Erin-Ticket zugeordnet.',
                        );
                    }
                    app(ZammadArticleReceiptRecorder::class)->record(
                        $ticket->getKey(),
                        $externalArticleId,
                        false,
                        $articleUpdatedAt,
                    );
                    $this->advanceArticleWatermark($ticket, $articleUpdatedAt);

                    continue;
                }

                if ($this->correlateErinEcho(
                    $ticket,
                    $article,
                    $articleSender,
                    $externalArticleId,
                    $messageMarkers,
                )) {
                    app(ZammadArticleReceiptRecorder::class)->record(
                        $ticket->getKey(),
                        $externalArticleId,
                        false,
                        $articleUpdatedAt,
                    );
                    $this->advanceArticleWatermark($ticket, $articleUpdatedAt);

                    continue;
                }

                $message = $ticket->messages()->create([
                    'author_id' => $articleSender === 'customer' ? $ticket->requester_id : null,
                    'external_article_id' => $externalArticleId,
                    'source' => 'zammad',
                    'delivery_status' => 'delivered',
                    'delivered_at' => now(),
                    'body' => $this->plainText($article['body']),
                    'is_internal' => false,
                    'attachments' => null,
                ]);
                $attachmentIds = $this->createAttachmentRecords($message, $article);
                app(ZammadArticleReceiptRecorder::class)->record(
                    $ticket->getKey(),
                    $externalArticleId,
                    false,
                    $articleUpdatedAt,
                );
                $this->advanceArticleWatermark($ticket, $articleUpdatedAt);
                $activity->record(
                    'support.message_received',
                    null,
                    $ticket->company_id,
                    $message,
                    ['ticket_number' => $ticket->number],
                    $ticket->requester,
                    'shared',
                );
                $outbox->record(
                    $message,
                    $attachmentIds,
                    $articleSender === 'agent',
                );
            }

            if ($shouldApplyState && $latestArticleSender !== null) {
                $ticket->update([
                    'status' => match ($ticket->status) {
                        SupportTicketStatus::Closed, SupportTicketStatus::Resolved => $ticket->status,
                        default => $latestArticleSender === 'customer'
                            ? SupportTicketStatus::Open
                            : SupportTicketStatus::WaitingForCustomer,
                    },
                ]);
            }
        });

        $outboxDispatcher->dispatchForTicket($ticket->getKey());

        return response()->json(['accepted' => true, 'matched' => true]);
    }

    /**
     * A Zammad trigger can call Erin before the outbound HTTP request has
     * returned to its queue job. In that window the external article ID is not
     * stored yet, so correlate the echo through the immutable Erin marker
     * instead of creating a second local message.
     *
     * @param  array<string, mixed>  $article
     */
    private function correlateErinEcho(
        SupportTicket $ticket,
        array $article,
        string $sender,
        string $externalArticleId,
        ZammadMessageMarker $messageMarkers,
    ): bool {
        $messageId = $messageMarkers->messageId(
            $article['subject'] ?? null,
            $ticket->getKey(),
        );
        if ($messageId === null) {
            return false;
        }

        /** @var SupportTicketMessage|null $message */
        $message = $ticket->messages()
            ->whereKey($messageId)
            ->where('source', 'erin')
            ->with('author:id,role')
            ->lockForUpdate()
            ->first();
        if (
            $message === null
            || $message->author === null
        ) {
            return false;
        }

        $expectedSender = $message->author->isPlatformStaff() ? 'agent' : 'customer';
        if (
            $sender !== $expectedSender
            || ! is_string($article['body'] ?? null)
            || ! hash_equals(
                trim($message->body),
                $this->plainText($article['body']),
            )
        ) {
            return false;
        }
        if (
            $message->external_article_id !== null
            && ! hash_equals($message->external_article_id, $externalArticleId)
        ) {
            return false;
        }

        $conflict = SupportTicketMessage::query()
            ->where('external_article_id', $externalArticleId)
            ->whereKeyNot($message->getKey())
            ->exists();
        if ($conflict) {
            throw new RuntimeException(
                'Ein Zammad-Artikel wurde bereits einer anderen Erin-Nachricht zugeordnet.',
            );
        }

        $message->forceFill([
            'external_article_id' => $externalArticleId,
            'delivery_status' => 'delivered',
            'delivered_at' => $message->delivered_at ?? now(),
        ])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $ticketPayload
     * @return list<array{
     *     payload: array<string, mixed>,
     *     sender: string|null,
     *     updated_at: CarbonImmutable|null
     * }>
     */
    private function unseenArticles(
        TicketingProvider $provider,
        SupportTicket $ticket,
        array $ticketPayload,
        string $externalTicketId,
    ): array {
        abort_if(
            array_key_exists('article', $ticketPayload)
            && $ticketPayload['article'] !== null
            && ! is_array($ticketPayload['article']),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
        abort_if(
            array_key_exists('article_ids', $ticketPayload)
            && $ticketPayload['article_ids'] !== null
            && ! is_array($ticketPayload['article_ids']),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        $articleIds = [];
        foreach ($ticketPayload['article_ids'] ?? [] as $articleId) {
            abort_unless(
                $this->validZammadId($articleId),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
            $articleIds[(string) $articleId] = true;
        }

        $embedded = is_array($ticketPayload['article'] ?? null)
            ? $this->normalizeArticle(
                $ticketPayload['article'],
                $externalTicketId,
            )
            : null;
        if ($embedded !== null) {
            $articleIds[(string) $embedded['payload']['id']] = true;
        }
        if ($articleIds === []) {
            return [];
        }

        $ids = array_keys($articleIds);
        $seen = [];
        $receipts = SupportZammadArticleReceipt::query()
            ->whereIn('external_article_id', $ids)
            ->get(['support_ticket_id', 'external_article_id']);
        foreach ($receipts as $receipt) {
            if ($receipt->support_ticket_id !== $ticket->getKey()) {
                throw new RuntimeException(
                    'Ein Zammad-Artikelbeleg gehört bereits zu einem anderen Erin-Ticket.',
                );
            }
            $seen[$receipt->external_article_id] = true;
        }

        $messages = SupportTicketMessage::query()
            ->whereIn('external_article_id', $ids)
            ->get(['support_ticket_id', 'external_article_id']);
        foreach ($messages as $message) {
            if ($message->support_ticket_id !== $ticket->getKey()) {
                throw new RuntimeException(
                    'Ein Zammad-Artikel gehört bereits zu einem anderen Erin-Ticket.',
                );
            }
            $seen[(string) $message->external_article_id] = true;
        }

        $normalized = [];
        foreach ($ids as $rawArticleId) {
            $articleId = (string) $rawArticleId;
            if (isset($seen[$articleId])) {
                continue;
            }

            $candidate = $embedded !== null
                && hash_equals(
                    (string) $embedded['payload']['id'],
                    $articleId,
                )
                    ? $embedded
                    : $this->normalizeArticle(
                        $provider->article($articleId),
                        $externalTicketId,
                        $articleId,
                    );
            $normalized[] = $candidate;
        }

        usort(
            $normalized,
            static function (array $left, array $right): int {
                $leftTimestamp = $left['updated_at']?->getTimestampMs() ?? 0;
                $rightTimestamp = $right['updated_at']?->getTimestampMs() ?? 0;
                if ($leftTimestamp !== $rightTimestamp) {
                    return $leftTimestamp <=> $rightTimestamp;
                }

                return (int) $left['payload']['id'] <=> (int) $right['payload']['id'];
            },
        );

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array{
     *     payload: array<string, mixed>,
     *     sender: string|null,
     *     updated_at: CarbonImmutable|null
     * }
     */
    private function normalizeArticle(
        array $article,
        string $externalTicketId,
        ?string $expectedArticleId = null,
    ): array {
        abort_unless(
            $this->validZammadId($article['id'] ?? null)
            && is_bool($article['internal'] ?? null)
            && $this->validZammadId($article['ticket_id'] ?? null)
            && hash_equals(
                $externalTicketId,
                (string) $article['ticket_id'],
            )
            && (
                $expectedArticleId === null
                || hash_equals($expectedArticleId, (string) $article['id'])
            ),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        if ($article['internal'] === true) {
            $updatedAt = null;
            if (isset($article['updated_at']) || isset($article['created_at'])) {
                $updatedAt = $this->externalTimestamp(
                    $article['updated_at'] ?? $article['created_at'],
                );
                abort_if($updatedAt === null, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return [
                'payload' => $article,
                'sender' => null,
                'updated_at' => $updatedAt,
            ];
        }

        $sender = $this->articleSender($article['sender'] ?? null);
        $updatedAt = $this->externalTimestamp(
            $article['updated_at'] ?? $article['created_at'] ?? null,
        );
        abort_if(
            $sender === null
            || $updatedAt === null
            || ! is_string($article['body'] ?? null)
            || (
                array_key_exists('subject', $article)
                && ! is_string($article['subject'])
            ),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
        $this->validateAttachments($article['attachments'] ?? []);

        return [
            'payload' => $article,
            'sender' => $sender,
            'updated_at' => $updatedAt,
        ];
    }

    private function validateAttachments(mixed $attachments): void
    {
        abort_unless(is_array($attachments), Response::HTTP_UNPROCESSABLE_ENTITY);
        foreach ($attachments as $attachment) {
            abort_unless(
                is_array($attachment)
                && $this->validZammadId($attachment['id'] ?? null)
                && (
                    ! array_key_exists('filename', $attachment)
                    || is_string($attachment['filename'])
                )
                && (
                    ! array_key_exists('size', $attachment)
                    || (
                        is_numeric($attachment['size'])
                        && (int) $attachment['size'] >= 0
                    )
                )
                && (
                    ! array_key_exists('preferences', $attachment)
                    || is_array($attachment['preferences'])
                ),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
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
            if (
                ! is_array($attachment)
                || ! $this->validZammadId($attachment['id'] ?? null)
            ) {
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

    private function validZammadId(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $value) === 1;
    }

    private function externalUpdatedAtMs(mixed $value): ?int
    {
        return $this->externalTimestamp($value)?->getTimestampMs();
    }

    private function externalTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '' || strlen($value) > 64) {
            return null;
        }

        try {
            $timestamp = CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }

        if (
            $timestamp->lessThan(CarbonImmutable::create(2000, 1, 1, 0, 0, 0, 'UTC'))
            || $timestamp->greaterThan(CarbonImmutable::now('UTC')->addMinutes(5))
        ) {
            return null;
        }

        return $timestamp;
    }

    private function articleSender(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $sender = mb_strtolower($value);

        return in_array($sender, ['agent', 'customer'], true) ? $sender : null;
    }

    private function advanceArticleWatermark(
        SupportTicket $ticket,
        CarbonImmutable $articleUpdatedAt,
    ): void {
        $articleUpdatedAtMs = $articleUpdatedAt->getTimestampMs();
        if (
            $ticket->external_last_article_at_ms !== null
            && $articleUpdatedAtMs <= $ticket->external_last_article_at_ms
        ) {
            return;
        }

        $updates = ['external_last_article_at_ms' => $articleUpdatedAtMs];
        if (
            $ticket->last_reply_at === null
            || $articleUpdatedAt->greaterThan($ticket->last_reply_at)
        ) {
            $updates['last_reply_at'] = $articleUpdatedAt;
        }
        $ticket->update($updates);
    }
}
