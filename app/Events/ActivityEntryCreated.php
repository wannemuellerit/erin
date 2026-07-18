<?php

namespace App\Events;

use App\Models\ActivityEntry;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActivityEntryCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ActivityEntry $entry) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        if (
            $this->entry->company_id !== null
            && in_array($this->entry->visibility, ['company', 'shared'], true)
        ) {
            $channels[] = new PrivateChannel('company.'.$this->entry->company_id);
        }

        if (
            $this->entry->subject_user_id !== null
            && in_array($this->entry->visibility, ['personal', 'shared'], true)
        ) {
            $channels[] = new PrivateChannel('user.'.$this->entry->subject_user_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'activity.created';
    }

    /**
     * @return array{entry: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        $this->entry->loadMissing('actor:id,name');

        return [
            'entry' => [
                'id' => $this->entry->getKey(),
                'event' => $this->entry->event,
                'actor' => $this->entry->actor?->only(['id', 'name']),
                'payload' => $this->entry->payload ?? [],
                'occurred_at' => $this->entry->occurred_at->toIso8601String(),
            ],
        ];
    }
}
