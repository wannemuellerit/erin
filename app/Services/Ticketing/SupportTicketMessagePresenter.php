<?php

namespace App\Services\Ticketing;

use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use Illuminate\Support\Facades\URL;

class SupportTicketMessagePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(SupportTicketMessage $message): array
    {
        $message->loadMissing(['author:id,name,role', 'files']);

        return [
            'id' => $message->getKey(),
            'author_id' => $message->author_id,
            'author' => $message->author?->only(['id', 'name', 'role']),
            'body' => $message->body,
            'is_internal' => $message->is_internal,
            'source' => $message->source,
            'delivery_status' => $message->delivery_status,
            'created_at' => $message->created_at?->toIso8601String(),
            'attachments' => $message->files
                ->map(fn (SupportTicketAttachment $attachment): array => $this->attachment($attachment))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attachment(SupportTicketAttachment $attachment): array
    {
        $downloadUrl = $attachment->scan_result === 'clean'
            && filled($attachment->disk)
            && filled($attachment->path)
            ? URL::temporarySignedRoute(
                'support.attachments.download',
                now()->addMinutes((int) config('support.attachments.signed_url_minutes', 10)),
                ['attachment' => $attachment],
            )
            : null;

        return [
            'id' => $attachment->getKey(),
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
            'scan_result' => $attachment->scan_result,
            'download_url' => $downloadUrl,
        ];
    }
}
