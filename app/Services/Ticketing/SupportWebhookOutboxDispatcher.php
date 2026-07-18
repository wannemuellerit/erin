<?php

namespace App\Services\Ticketing;

use App\Jobs\ProcessSupportWebhookOutbox;
use App\Models\SupportWebhookOutbox;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;

class SupportWebhookOutboxDispatcher
{
    public function __construct(private readonly Dispatcher $bus) {}

    public function dispatchForTicket(int $ticketId, int $limit = 100): int
    {
        return $this->dispatchQuery(
            SupportWebhookOutbox::query()
                ->whereHas(
                    'message',
                    fn ($query) => $query->where('support_ticket_id', $ticketId),
                ),
            $limit,
        );
    }

    public function dispatchPending(int $limit = 100): int
    {
        return $this->dispatchQuery(SupportWebhookOutbox::query(), $limit);
    }

    /**
     * @param  Builder<SupportWebhookOutbox>  $query
     */
    private function dispatchQuery(Builder $query, int $limit): int
    {
        $ids = $query
            ->whereNull('processed_at')
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('locked_at')
                    ->orWhere('locked_at', '<=', now()->subMinutes(10));
            })
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->pluck('id');

        foreach ($ids as $id) {
            $this->bus->dispatch(new ProcessSupportWebhookOutbox((int) $id));
        }

        return $ids->count();
    }
}
