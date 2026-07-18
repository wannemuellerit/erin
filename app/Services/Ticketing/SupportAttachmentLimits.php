<?php

namespace App\Services\Ticketing;

use App\Exceptions\SupportAttachmentLimitExceeded;
use Illuminate\Http\UploadedFile;

class SupportAttachmentLimits
{
    public function maxFiles(): int
    {
        return max(1, (int) config('support.attachments.max_files', 8));
    }

    public function maxFileBytes(): int
    {
        return max(1, (int) config('support.attachments.max_kilobytes', 10240)) * 1024;
    }

    public function maxTotalBytes(): int
    {
        return max(1, (int) config('support.attachments.max_total_kilobytes', 15360)) * 1024;
    }

    /**
     * @return array{maxFiles: int, maxFileMegabytes: float, maxTotalMegabytes: float}
     */
    public function forFrontend(): array
    {
        return [
            'maxFiles' => $this->maxFiles(),
            'maxFileMegabytes' => $this->maxFileBytes() / 1024 / 1024,
            'maxTotalMegabytes' => $this->maxTotalBytes() / 1024 / 1024,
        ];
    }

    public function assertFileSize(int $size): void
    {
        if ($size < 0 || $size > $this->maxFileBytes()) {
            throw new SupportAttachmentLimitExceeded(
                'Ein Supportanhang überschreitet die erlaubte Einzelgröße.',
            );
        }
    }

    public function assertFileCount(int $count): void
    {
        if ($count < 0 || $count > $this->maxFiles()) {
            throw new SupportAttachmentLimitExceeded(
                'Eine Supportnachricht enthält zu viele Anhänge.',
            );
        }
    }

    public function assertTotalSize(int $size): void
    {
        if ($size > $this->maxTotalBytes()) {
            throw new SupportAttachmentLimitExceeded(
                'Die Supportanhänge überschreiten zusammen die erlaubte Gesamtgröße.',
            );
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function assertUploads(array $files): void
    {
        $this->assertFileCount(count($files));
        $totalBytes = 0;

        foreach ($files as $file) {
            $size = max(0, (int) $file->getSize());
            $this->assertFileSize($size);
            $totalBytes += $size;
            $this->assertTotalSize($totalBytes);
        }
    }
}
