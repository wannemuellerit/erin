<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly int $userId,
        public readonly string $readAt,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('conversation.'.$this->conversation->getKey());
    }

    public function broadcastAs(): string
    {
        return 'conversation.read';
    }

    /** @return array{user_id: int, read_at: string} */
    public function broadcastWith(): array
    {
        return ['user_id' => $this->userId, 'read_at' => $this->readAt];
    }
}
