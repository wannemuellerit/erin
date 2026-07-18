<?php

namespace App\Services\Ticketing;

use App\Models\SupportTicketMessage;
use RuntimeException;

class SupportAttachmentPayloadBuilder
{
    public function __construct(
        private readonly SupportAttachmentIntegrityVerifier $integrity,
        private readonly SupportAttachmentLimits $limits,
    ) {}

    /**
     * @return list<array{filename: string, data: string, mime-type: string}>
     */
    public function forMessage(SupportTicketMessage $message): array
    {
        $message->loadMissing('files');
        $allowedMimeTypes = config('support.attachments.allowed_mime_types', []);
        $payloads = [];
        $totalBytes = 0;

        foreach ($message->files as $attachment) {
            if ($attachment->scan_result !== 'clean') {
                throw new RuntimeException('Ein Supportanhang ist noch nicht sicherheitsgeprüft.');
            }
            if (
                $attachment->size_bytes === null
                || ! in_array($attachment->mime_type, $allowedMimeTypes, true)
            ) {
                throw new RuntimeException('Ein Supportanhang verletzt die freigegebenen Datei-Grenzen.');
            }
            $this->limits->assertFileSize($attachment->size_bytes);
            $totalBytes += $attachment->size_bytes;
            $this->limits->assertTotalSize($totalBytes);

            $contents = $this->integrity->verifiedContents($attachment);

            $payloads[] = [
                'filename' => $attachment->original_name,
                'data' => base64_encode($contents),
                'mime-type' => (string) $attachment->mime_type,
            ];
        }

        return $payloads;
    }
}
