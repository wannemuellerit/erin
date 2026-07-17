<?php

namespace App\Events;

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('conversation.'.$this->message->conversation_id);
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->message->loadMissing(['sender:id,name', 'attachments']);

        return [
            'message' => [
                'id' => $this->message->getKey(),
                'sender' => $this->message->sender?->only(['id', 'name']),
                'sender_id' => $this->message->sender_id,
                'reply_to_id' => $this->message->reply_to_id,
                'type' => $this->message->type,
                'body' => $this->message->body,
                'translations' => $this->message->translations,
                'created_at' => $this->message->created_at?->toIso8601String(),
                'attachments' => $this->message->attachments
                    ->map(fn (MessageAttachment $attachment): array => [
                        'id' => $attachment->getKey(),
                        'original_name' => $attachment->original_name,
                        'mime_type' => $attachment->mime_type,
                        'size_bytes' => $attachment->size_bytes,
                        'scan_result' => $attachment->scan_result,
                        'download_url' => $attachment->scan_result === 'clean'
                            ? URL::temporarySignedRoute(
                                'messages.attachments.download',
                                now()->addMinutes(15),
                                ['attachment' => $attachment],
                            )
                            : null,
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }
}
