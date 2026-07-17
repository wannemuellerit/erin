<?php

namespace App\Jobs;

use App\Enums\CandidateDocumentStatus;
use App\Models\CandidateDocument;
use App\Services\Documents\ClamAvScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ScanCandidateDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $documentId) {}

    public function handle(ClamAvScanner $scanner): void
    {
        $document = CandidateDocument::query()->findOrFail($this->documentId);
        $stream = Storage::disk($document->disk)->readStream($document->path);

        if (! is_resource($stream)) {
            throw new \RuntimeException('Die private Datei konnte nicht geöffnet werden.');
        }

        try {
            $result = $scanner->scan($stream);
        } finally {
            fclose($stream);
        }

        if ($result === 'infected') {
            $document->update([
                'scan_result' => 'infected',
                'scan_completed_at' => now(),
                'status' => CandidateDocumentStatus::Rejected,
                'rejection_reason' => __('Die Datei wurde aus Sicherheitsgründen abgelehnt.'),
            ]);

            return;
        }

        $document->update([
            'scan_result' => 'clean',
            'scan_completed_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        CandidateDocument::query()->whereKey($this->documentId)->update([
            'scan_result' => 'scan_failed',
            'scan_completed_at' => now(),
            'rejection_reason' => __('Der Sicherheitscheck ist fehlgeschlagen. Bitte lade die Datei erneut hoch.'),
        ]);
    }
}
