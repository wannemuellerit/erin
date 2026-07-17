<?php

namespace App\Jobs;

use App\Models\JobMedia;
use App\Services\Documents\ClamAvScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ScanJobMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $mediaId) {}

    public function handle(ClamAvScanner $scanner): void
    {
        $media = JobMedia::query()->findOrFail($this->mediaId);
        $stream = Storage::disk($media->disk)->readStream($media->path);

        if (! is_resource($stream)) {
            throw new RuntimeException('Stellenmedium konnte nicht gelesen werden.');
        }

        try {
            $result = $scanner->scan($stream);
        } finally {
            fclose($stream);
        }

        $media->update([
            'scan_result' => $result,
            'scan_completed_at' => now(),
        ]);

        if ($result === 'infected') {
            Storage::disk($media->disk)->delete($media->path);
        }
    }

    public function failed(Throwable $exception): void
    {
        JobMedia::query()->whereKey($this->mediaId)->update([
            'scan_result' => 'scan_failed',
            'scan_completed_at' => now(),
        ]);
    }
}
