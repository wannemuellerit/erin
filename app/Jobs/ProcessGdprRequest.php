<?php

namespace App\Jobs;

use App\Enums\GdprRequestStatus;
use App\Models\GdprRequest;
use App\Services\Privacy\GdprErasure;
use App\Services\Privacy\GdprExportBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProcessGdprRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $gdprRequestId) {}

    public function handle(
        GdprExportBuilder $exports,
        GdprErasure $erasure,
    ): void {
        $request = GdprRequest::query()->with('user')->findOrFail($this->gdprRequestId);
        if ($request->status === GdprRequestStatus::Completed) {
            return;
        }

        $request->forceFill([
            'status' => GdprRequestStatus::Processing,
            'processing_started_at' => $request->processing_started_at ?? now(),
            'failed_at' => null,
            'failure_reason' => null,
        ])->save();

        try {
            $summary = $request->type === 'export'
                ? $this->export($request, $exports)
                : $erasure->erase($request, $request->user);

            $request->forceFill([
                'status' => GdprRequestStatus::Completed,
                'result_summary' => $summary,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $request->forceFill([
                'status' => GdprRequestStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => Str::limit($exception->getMessage(), 1900),
            ])->save();

            throw $exception;
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function export(
        GdprRequest $request,
        GdprExportBuilder $exports,
    ): array {
        $payload = $exports->build($request->user);
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $path = sprintf('gdpr/exports/%d-%s.erin', $request->getKey(), Str::uuid());
        Storage::disk('private')->put($path, Crypt::encryptString($encoded));
        $expiresAt = now()->addDays(7);

        $request->forceFill([
            'export_disk' => 'private',
            'export_path' => $path,
            'export_expires_at' => $expiresAt,
            'downloaded_at' => null,
        ])->save();

        return [
            'subject_user_id' => $request->user_id,
            'bytes' => strlen($encoded),
            'expires_at' => $expiresAt->toIso8601String(),
            'result' => 'encrypted_export_created',
        ];
    }
}
