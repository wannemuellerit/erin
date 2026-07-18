<?php

namespace App\Events;

use App\Models\SupportTicketMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportTicketMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly SupportTicketMessage $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('support-ticket.'.$this->message->support_ticket_id);
    }

    public function broadcastAs(): string
    {
        return 'support.message.created';
    }

    public function broadcastWhen(): bool
    {
        return ! $this->message->is_internal;
    }

    /**
     * @return array{message: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        $this->message->loadMissing('author:id,name,role');

        return [
            'message' => [
                'id' => $this->message->getKey(),
                'author_id' => $this->message->author_id,
                'author' => $this->message->author?->only(['id', 'name', 'role']),
                'body' => $this->message->body,
                'is_internal' => $this->message->is_internal,
                'source' => $this->message->source,
                'delivery_status' => $this->message->delivery_status,
                'created_at' => $this->message->created_at?->toIso8601String(),
            ],
        ];
    }
}
