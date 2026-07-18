<?php

namespace App\Jobs;

use App\Http\Controllers\Integrations\ZammadWebhookController;
use App\Models\SupportTicket;
use App\Models\SupportZammadWebhookInbox;
use App\Services\Ticketing\SupportZammadWebhookInboxRetention;
use App\Services\Ticketing\ZammadWebhookSignature;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProcessSupportZammadWebhookInbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 10;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 300, 900];

    public function __construct(public readonly int $inboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->inboxId;
    }

    public function handle(ZammadWebhookSignature $signatures): void
    {
        if (
            app(SupportZammadWebhookInboxRetention::class)
                ->terminalizeExpired($this->inboxId) > 0
        ) {
            return;
        }

        $exhausted = SupportZammadWebhookInbox::query()
            ->whereKey($this->inboxId)
            ->whereNull('processed_at')
            ->whereNull('terminal_at')
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->where(function ($query): void {
                $query->whereNull('locked_at')
                    ->orWhere('locked_at', '<=', now()->subMinutes(10));
            })
            ->update([
                'locked_at' => null,
                'terminal_at' => now(),
                'last_error' => 'Die Zammad-Webhook-Wiedergabe wurde nach einem unterbrochenen Maximalversuch beendet.',
                'raw_payload' => '',
                'updated_at' => now(),
            ]);
        if ($exhausted > 0) {
            return;
        }

        $claimed = SupportZammadWebhookInbox::query()
            ->whereKey($this->inboxId)
            ->whereNull('processed_at')
            ->whereNull('terminal_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('locked_at')
                    ->orWhere('locked_at', '<=', now()->subMinutes(10));
            })
            ->update([
                'locked_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);
        if ($claimed === 0) {
            return;
        }

        /** @var SupportZammadWebhookInbox $entry */
        $entry = SupportZammadWebhookInbox::query()->findOrFail($this->inboxId);
        if (! hash_equals(
            $entry->payload_sha256,
            hash('sha256', $entry->raw_payload),
        )) {
            $this->failTerminally(
                $entry,
                'Der gespeicherte Zammad-Webhook hat seine Integritätsprüfung nicht bestanden.',
            );

            return;
        }

        if (! SupportTicket::query()
            ->where('external_system', 'zammad')
            ->where('external_id', $entry->external_ticket_id)
            ->exists()) {
            $this->releaseEntry($entry);

            return;
        }

        $secret = (string) config('services.zammad.webhook_secret');
        if (strlen($secret) < 32) {
            $this->failTerminally(
                $entry,
                'Die Zammad-Webhook-Inbox kann ohne gültiges Secret nicht wiedergegeben werden.',
            );

            return;
        }

        $request = Request::create(
            '/integrations/zammad/webhook',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE' => $signatures->create(
                    $entry->raw_payload,
                    $secret,
                ),
                'HTTP_X_ZAMMAD_DELIVERY' => 'erin-replay-'
                    .$entry->payload_sha256,
            ],
            $entry->raw_payload,
        );

        try {
            $response = app()->call(
                [app(ZammadWebhookController::class), '__invoke'],
                ['request' => $request],
            );
        } catch (Throwable $exception) {
            if (! $this->retryOrFailTerminally($entry)) {
                return;
            }

            throw $exception;
        }

        if (! $response instanceof JsonResponse) {
            if (! $this->retryOrFailTerminally($entry)) {
                return;
            }

            throw new RuntimeException(
                'Die Zammad-Webhook-Wiedergabe lieferte keine JSON-Antwort.',
            );
        }

        $status = $response->getStatusCode();
        $responsePayload = $response->getData(true);
        if (
            $status >= 200
            && $status < 300
            && ($responsePayload['matched'] ?? null) === true
        ) {
            $entry->update([
                'locked_at' => null,
                'processed_at' => now(),
                'last_error' => null,
                'raw_payload' => '',
            ]);

            return;
        }

        if (
            $status === 202
            && ($responsePayload['matched'] ?? null) === false
        ) {
            $this->releaseEntry($entry);

            return;
        }

        if ($status >= 400 && $status < 500) {
            $this->failTerminally(
                $entry,
                'Die gespeicherte Zammad-Nachricht wurde bei der Wiedergabe sicher abgelehnt.',
            );

            return;
        }

        if (! $this->retryOrFailTerminally($entry)) {
            return;
        }
        throw new RuntimeException(
            'Die Zammad-Webhook-Wiedergabe war vorübergehend nicht erfolgreich.',
        );
    }

    public function failed(Throwable $exception): void
    {
        $entry = SupportZammadWebhookInbox::query()
            ->whereKey($this->inboxId)
            ->whereNull('processed_at')
            ->whereNull('terminal_at')
            ->first();
        if ($entry === null) {
            return;
        }
        if ($entry->attempts >= self::MAX_ATTEMPTS) {
            $this->failTerminally(
                $entry,
                'Die Zammad-Webhook-Wiedergabe ist nach der maximalen Anzahl Versuche fehlgeschlagen.',
            );

            return;
        }

        $entry->update([
            'locked_at' => null,
            'available_at' => now()->addMinutes(5),
            'last_error' => 'Die Zammad-Webhook-Wiedergabe wartet auf einen erneuten Versuch.',
        ]);
    }

    private function releaseEntry(SupportZammadWebhookInbox $entry): void
    {
        $entry->update([
            'locked_at' => null,
            'available_at' => now()->addSeconds(30),
            'attempts' => max(0, $entry->attempts - 1),
            'last_error' => 'Die lokale Zammad-Ticketzuordnung ist noch nicht verfügbar.',
        ]);
    }

    private function retryOrFailTerminally(
        SupportZammadWebhookInbox $entry,
    ): bool {
        if ($entry->attempts >= self::MAX_ATTEMPTS) {
            $this->failTerminally(
                $entry,
                'Die Zammad-Webhook-Wiedergabe ist nach der maximalen Anzahl Versuche fehlgeschlagen.',
            );

            return false;
        }

        $entry->update([
            'locked_at' => null,
            'available_at' => now()->addSeconds(30),
            'last_error' => 'Die Zammad-Webhook-Wiedergabe wartet auf einen erneuten Versuch.',
        ]);

        return true;
    }

    private function failTerminally(
        SupportZammadWebhookInbox $entry,
        string $message,
    ): void {
        $entry->update([
            'locked_at' => null,
            'terminal_at' => now(),
            'last_error' => $message,
            'raw_payload' => '',
        ]);
    }
}
