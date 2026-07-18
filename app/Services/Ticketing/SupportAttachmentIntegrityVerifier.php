<?php

namespace App\Services\Ticketing;

use App\Exceptions\SupportAttachmentIntegrityException;
use App\Exceptions\SupportAttachmentLimitExceeded;
use App\Models\SupportTicketAttachment;
use Illuminate\Support\Facades\Storage;

class SupportAttachmentIntegrityVerifier
{
    public function __construct(
        private readonly SupportAttachmentLimits $limits,
    ) {}

    public function verifiedContents(SupportTicketAttachment $attachment): string
    {
        $this->assertStoredReference($attachment);
        if ($attachment->size_bytes === null) {
            throw new SupportAttachmentIntegrityException(
                'Für den Supportanhang fehlt die geprüfte Dateigröße.',
            );
        }
        try {
            $this->limits->assertFileSize($attachment->size_bytes);
        } catch (SupportAttachmentLimitExceeded) {
            throw new SupportAttachmentIntegrityException(
                'Der Supportanhang überschreitet die erlaubte Dateigröße.',
            );
        }
        $contents = Storage::disk((string) $attachment->disk)
            ->get((string) $attachment->path);
        $this->assertMatches(
            $attachment,
            strlen($contents),
            hash('sha256', $contents),
        );

        return $contents;
    }

    private function assertStoredReference(SupportTicketAttachment $attachment): void
    {
        if (
            ! filled($attachment->disk)
            || ! filled($attachment->path)
            || ! Storage::disk((string) $attachment->disk)
                ->exists((string) $attachment->path)
        ) {
            throw new SupportAttachmentIntegrityException(
                'Der geprüfte Supportanhang fehlt im privaten Speicher.',
            );
        }
    }

    private function assertMatches(
        SupportTicketAttachment $attachment,
        int $actualSize,
        string $actualChecksum,
    ): void {
        if (
            $attachment->size_bytes === null
            || ! filled($attachment->checksum_sha256)
            || $actualSize !== $attachment->size_bytes
            || ! hash_equals((string) $attachment->checksum_sha256, $actualChecksum)
        ) {
            throw new SupportAttachmentIntegrityException(
                'Der Supportanhang stimmt nicht mehr mit dem geprüften Upload überein.',
            );
        }
    }
}
