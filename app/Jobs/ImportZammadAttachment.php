<?php

namespace App\Jobs;

use App\Contracts\TicketingProvider;
use App\Events\SupportTicketMessageCreated;
use App\Exceptions\SupportAttachmentLimitExceeded;
use App\Models\SupportTicketAttachment;
use App\Services\Documents\ClamAvScanner;
use App\Services\Ticketing\SupportAttachmentLimits;
use finfo;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ImportZammadAttachment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $attachmentId) {}

    public function uniqueId(): string
    {
        return (string) $this->attachmentId;
    }

    public function handle(
        TicketingProvider $provider,
        ClamAvScanner $scanner,
        SupportAttachmentLimits $limits,
    ): void {
        $attachment = SupportTicketAttachment::query()
            ->with('message.supportTicket')
            ->findOrFail($this->attachmentId);
        if ($attachment->scan_result === 'clean' && filled($attachment->path)) {
            return;
        }
        if ($attachment->source !== 'zammad' || $attachment->external_id === null) {
            throw new RuntimeException('Der externe Supportanhang ist unvollständig referenziert.');
        }

        $ticket = $attachment->message->supportTicket;
        if ($ticket->external_id === null || $attachment->message->external_article_id === null) {
            throw new RuntimeException('Der Zammad-Artikel ist nicht vollständig referenziert.');
        }

        $temporary = tmpfile();
        if (! is_resource($temporary)) {
            throw new RuntimeException('Der Zammad-Anhang konnte nicht sicher zwischengespeichert werden.');
        }

        $storedDisk = null;
        $storedPath = null;
        $storageReferencePersisted = false;
        try {
            try {
                $download = $provider->downloadAttachment(
                    $ticket->external_id,
                    $attachment->message->external_article_id,
                    $attachment->external_id,
                    $temporary,
                    $limits->maxFileBytes(),
                );
            } catch (SupportAttachmentLimitExceeded) {
                $this->reject($attachment);

                return;
            }

            if ($download['size_bytes'] === 0) {
                $this->reject($attachment);

                return;
            }

            $extension = mb_strtolower((string) pathinfo(
                $attachment->original_name,
                PATHINFO_EXTENSION,
            ));
            $allowedExtensions = config('support.attachments.allowed_extensions', []);
            if (! in_array($extension, $allowedExtensions, true)) {
                $this->reject($attachment);

                return;
            }

            $metadata = stream_get_meta_data($temporary);
            $temporaryPath = $metadata['uri'] ?? null;
            if (! is_string($temporaryPath)) {
                throw new RuntimeException('Der Zammad-Anhang besitzt keinen prüfbaren temporären Pfad.');
            }
            $detectedMimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
            $allowedMimeTypes = config('support.attachments.allowed_mime_types', []);
            if (
                ! is_string($detectedMimeType)
                || ! in_array($detectedMimeType, $allowedMimeTypes, true)
            ) {
                $this->reject($attachment);

                return;
            }

            try {
                $this->reserveMessageCapacity(
                    $attachment,
                    $download['size_bytes'],
                    $limits,
                );
            } catch (SupportAttachmentLimitExceeded) {
                $this->reject($attachment);

                return;
            }

            $disk = (string) config('support.attachments.disk', 'private');
            $path = $this->storagePath(
                $ticket->getKey(),
                $attachment->getKey(),
                $extension,
            );
            rewind($temporary);
            throw_unless(
                Storage::disk($disk)->put($path, $temporary),
                RuntimeException::class,
                'Der Zammad-Anhang konnte nicht privat gespeichert werden.',
            );
            $storedDisk = $disk;
            $storedPath = $path;

            $stream = Storage::disk($disk)->readStream($path);
            if (! is_resource($stream)) {
                Storage::disk($disk)->delete($path);
                throw new RuntimeException('Der Zammad-Anhang konnte nicht sicherheitsgeprüft werden.');
            }

            try {
                $scanResult = $scanner->scan($stream);
            } catch (Throwable $exception) {
                Storage::disk($disk)->delete($path);
                throw $exception;
            } finally {
                fclose($stream);
            }

            if ($scanResult === 'infected') {
                Storage::disk($disk)->delete($path);
            }

            $attachment->update([
                'disk' => $scanResult === 'clean' ? $disk : null,
                'path' => $scanResult === 'clean' ? $path : null,
                'mime_type' => $detectedMimeType,
                'size_bytes' => $download['size_bytes'],
                'checksum_sha256' => $download['checksum_sha256'],
                'scan_result' => $scanResult,
                'scan_completed_at' => now(),
            ]);
            $storageReferencePersisted = true;
            $this->broadcastMessage($attachment);
        } catch (Throwable $exception) {
            if (
                ! $storageReferencePersisted
                && $storedDisk !== null
                && $storedPath !== null
            ) {
                Storage::disk($storedDisk)->delete($storedPath);
            }

            throw $exception;
        } finally {
            fclose($temporary);
        }
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
        $extension = mb_strtolower((string) pathinfo(
            $attachment->original_name,
            PATHINFO_EXTENSION,
        ));
        if (
            $attachment->source === 'zammad'
            && $extension !== ''
            && $attachment->message !== null
        ) {
            Storage::disk((string) config('support.attachments.disk', 'private'))
                ->delete($this->storagePath(
                    $attachment->message->support_ticket_id,
                    $attachment->getKey(),
                    $extension,
                ));
        }
        $attachment->update([
            'disk' => null,
            'path' => null,
            'scan_result' => 'scan_failed',
            'scan_completed_at' => now(),
        ]);
        $this->broadcastMessage($attachment);
    }

    private function reject(SupportTicketAttachment $attachment): void
    {
        $attachment->update([
            'disk' => null,
            'path' => null,
            'scan_result' => 'rejected',
            'scan_completed_at' => now(),
        ]);
        $this->broadcastMessage($attachment);
    }

    private function reserveMessageCapacity(
        SupportTicketAttachment $attachment,
        int $actualSize,
        SupportAttachmentLimits $limits,
    ): void {
        DB::transaction(function () use ($attachment, $actualSize, $limits): void {
            $attachments = SupportTicketAttachment::query()
                ->where('support_ticket_message_id', $attachment->support_ticket_message_id)
                ->whereIn('scan_result', ['pending', 'clean'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'size_bytes']);
            $totalBytes = $attachments->sum(
                static fn (SupportTicketAttachment $candidate): int => $candidate->getKey() === $attachment->getKey()
                    ? $actualSize
                    : max(0, (int) $candidate->size_bytes),
            );
            $limits->assertTotalSize($totalBytes);
            SupportTicketAttachment::query()
                ->whereKey($attachment->getKey())
                ->update(['size_bytes' => $actualSize]);
        });

        $attachment->size_bytes = $actualSize;
    }

    private function broadcastMessage(SupportTicketAttachment $attachment): void
    {
        SupportTicketMessageCreated::dispatch(
            $attachment->message()->with('files')->firstOrFail(),
        );
    }

    private function storagePath(
        int $supportTicketId,
        int $attachmentId,
        string $extension,
    ): string {
        return sprintf(
            'support-tickets/%d/zammad/%d.%s',
            $supportTicketId,
            $attachmentId,
            $extension,
        );
    }
}
