<?php

namespace App\Services\Ticketing;

use App\Models\SupportTicketAttachment;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SupportAttachmentOrphanCleaner
{
    /**
     * @return array{
     *     scanned: int,
     *     eligible: int,
     *     deleted: int,
     *     metadata_errors: int,
     *     deletion_errors: int,
     *     eligible_paths: list<string>
     * }
     */
    public function prune(bool $execute): array
    {
        $diskName = (string) config('support.attachments.disk', 'private');
        $disk = Storage::disk($diskName);
        $referencedPaths = SupportTicketAttachment::query()
            ->where('disk', $diskName)
            ->whereNotNull('path')
            ->pluck('path')
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->flip();
        $cutoff = now()
            ->subHours(max(
                1,
                (int) config('support.attachments.orphan_grace_hours', 24),
            ))
            ->getTimestamp();
        $result = [
            'scanned' => 0,
            'eligible' => 0,
            'deleted' => 0,
            'metadata_errors' => 0,
            'deletion_errors' => 0,
            'eligible_paths' => [],
        ];

        foreach ($disk->allFiles('support-tickets') as $path) {
            $path = $this->normalizeLegacyS3ListingPath($path, $diskName);
            if (
                preg_match(
                    '#\Asupport-tickets/[1-9][0-9]*/(?:erin|zammad)/[A-Za-z0-9][A-Za-z0-9._-]{0,254}\z#D',
                    $path,
                ) !== 1
            ) {
                continue;
            }
            $result['scanned']++;
            if ($referencedPaths->has($path)) {
                continue;
            }

            try {
                if ($disk->lastModified($path) > $cutoff) {
                    continue;
                }
            } catch (Throwable) {
                $result['metadata_errors']++;

                continue;
            }

            $result['eligible']++;
            $result['eligible_paths'][] = $path;
            if (! $execute) {
                continue;
            }

            try {
                if ($disk->delete($path)) {
                    $result['deleted']++;
                } else {
                    $result['deletion_errors']++;
                }
            } catch (Throwable) {
                $result['deletion_errors']++;
            }
        }

        return $result;
    }

    private function normalizeLegacyS3ListingPath(string $path, string $diskName): string
    {
        if (
            str_starts_with($path, 'upport-tickets/')
            && config("filesystems.disks.{$diskName}.driver") === 's3'
            && str_starts_with(
                (string) config("filesystems.disks.{$diskName}.root"),
                DIRECTORY_SEPARATOR,
            )
        ) {
            return 's'.$path;
        }

        return $path;
    }
}
