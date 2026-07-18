<?php

namespace App\Events;

use App\Models\SupportTicketMessage;
use App\Services\Ticketing\SupportTicketMessagePresenter;
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
        return [
            'message' => app(SupportTicketMessagePresenter::class)
                ->present($this->message),
        ];
    }
}
