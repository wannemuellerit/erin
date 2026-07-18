<?php

namespace App\Jobs;

use App\Models\SupportWebhookOutbox;
use App\Services\Ticketing\SupportWebhookOutboxEffects;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessSupportWebhookOutbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 300, 900];

    public function __construct(public readonly int $outboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->outboxId;
    }

    public function handle(SupportWebhookOutboxEffects $effects): void
    {
        $claimed = SupportWebhookOutbox::query()
            ->whereKey($this->outboxId)
            ->whereNull('processed_at')
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

        /** @var SupportWebhookOutbox $entry */
        $entry = SupportWebhookOutbox::query()->findOrFail($this->outboxId);

        try {
            $effects->deliver($entry);
        } catch (Throwable $exception) {
            $entry->update([
                'locked_at' => null,
                'available_at' => now()->addSeconds(30),
                'last_error' => 'Der Support-Outbox-Effekt konnte noch nicht zugestellt werden.',
            ]);

            throw $exception;
        }

        $entry->update([
            'locked_at' => null,
            'processed_at' => now(),
            'last_error' => null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        SupportWebhookOutbox::query()
            ->whereKey($this->outboxId)
            ->whereNull('processed_at')
            ->update([
                'locked_at' => null,
                'available_at' => now()->addMinutes(5),
                'last_error' => 'Der Support-Outbox-Effekt wartet auf einen erneuten Zustellversuch.',
            ]);
    }
}
