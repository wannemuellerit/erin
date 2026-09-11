<?php

namespace App\Events;

use App\Models\MessageTranslation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageTranslated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly MessageTranslation $translation) {}

    public function broadcastOn(): PrivateChannel
    {
        $this->translation->loadMissing('message');

        return new PrivateChannel('conversation.'.$this->translation->message->conversation_id);
    }

    public function broadcastAs(): string
    {
        return 'message.translated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->translation->message_id,
            'target_locale' => $this->translation->target_locale,
            'translation' => [
                'status' => $this->translation->status,
                'body' => $this->translation->translated_body,
                'model' => $this->translation->model,
                'prompt_version' => $this->translation->prompt_version,
            ],
        ];
    }
}
