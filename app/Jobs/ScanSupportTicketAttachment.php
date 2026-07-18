<?php

namespace App\Jobs;

use App\Events\SupportTicketMessageCreated;
use App\Models\SupportTicketAttachment;
use App\Services\Documents\ClamAvScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ScanSupportTicketAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $attachmentId) {}

    public function handle(ClamAvScanner $scanner): void
    {
        $attachment = SupportTicketAttachment::query()
            ->with('message.supportTicket')
            ->findOrFail($this->attachmentId);
        if (! filled($attachment->disk) || ! filled($attachment->path)) {
            throw new RuntimeException('Der Supportanhang besitzt keinen privaten Speicherort.');
        }

        $stream = Storage::disk($attachment->disk)->readStream($attachment->path);
        throw_unless(is_resource($stream), RuntimeException::class, 'Supportanhang konnte nicht gelesen werden.');

        try {
            $result = $scanner->scan($stream);
        } finally {
            fclose($stream);
        }

        $attachment->update([
            'scan_result' => $result,
            'scan_completed_at' => now(),
        ]);

        if ($result === 'infected') {
            Storage::disk($attachment->disk)->delete($attachment->path);
            $attachment->message->update([
                'delivery_status' => 'failed',
                'external_reconcile_not_before' => null,
            ]);
            if (
                $attachment->message->supportTicket->external_id === null
                && $this->belongsToOpeningMessage($attachment)
            ) {
                $attachment->message->supportTicket->update([
                    'sync_status' => 'failed',
                    'sync_error' => 'Ein Supportanhang wurde als unsicher erkannt.',
                    'external_reconcile_not_before' => null,
                ]);
            }
            SupportTicketMessageCreated::dispatch(
                $attachment->message->fresh(['files']) ?? $attachment->message,
            );

            return;
        }

        SupportTicketMessageCreated::dispatch(
            $attachment->message->fresh(['files']) ?? $attachment->message,
        );

        $ticket = $attachment->message->supportTicket;
        if ($ticket->external_id === null) {
            SyncSupportTicketToProvider::dispatch($ticket->getKey());

            return;
        }

        SyncSupportMessageToProvider::dispatch($attachment->message->getKey());
    }

    public function failed(Throwable $exception): void
    {
        $attachment = SupportTicketAttachment::query()
            ->with('message.supportTicket')
            ->find($this->attachmentId);
        if ($attachment === null) {
            return;
        }

        if (filled($attachment->disk) && filled($attachment->path)) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }
        $attachment->update([
            'scan_result' => 'scan_failed',
            'scan_completed_at' => now(),
        ]);
        $attachment->message->update([
            'delivery_status' => 'failed',
            'external_reconcile_not_before' => null,
        ]);
        if (
            $attachment->message->supportTicket->external_id === null
            && $this->belongsToOpeningMessage($attachment)
        ) {
            $attachment->message->supportTicket->update([
                'sync_status' => 'failed',
                'sync_error' => 'Ein Supportanhang konnte nicht sicherheitsgeprüft werden.',
                'external_reconcile_not_before' => null,
            ]);
        }
        SupportTicketMessageCreated::dispatch(
            $attachment->message()->with('files')->firstOrFail(),
        );
    }

    private function belongsToOpeningMessage(
        SupportTicketAttachment $attachment,
    ): bool {
        $openingMessageId = $attachment->message
            ->supportTicket
            ->messages()
            ->min('id');

        return is_numeric($openingMessageId)
            && (int) $openingMessageId === $attachment->support_ticket_message_id;
    }
}
