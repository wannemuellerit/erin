<?php

namespace App\Jobs;

use App\Models\MessageAttachment;
use App\Services\Documents\ClamAvScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ScanMessageAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $attachmentId) {}

    public function handle(ClamAvScanner $scanner): void
    {
        $attachment = MessageAttachment::query()->findOrFail($this->attachmentId);
        $stream = Storage::disk($attachment->disk)->readStream($attachment->path);
        throw_unless(is_resource($stream), \RuntimeException::class, 'Anhang konnte nicht gelesen werden.');

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
        }
    }

    public function failed(Throwable $exception): void
    {
        MessageAttachment::query()->whereKey($this->attachmentId)->update([
            'scan_result' => 'scan_failed',
            'scan_completed_at' => now(),
        ]);
    }
}
