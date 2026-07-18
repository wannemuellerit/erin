<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GdprRequestStatus;
use App\Http\Requests\Admin\StoreGdprRequestRequest;
use App\Http\Requests\Admin\UpdateGdprRequestRequest;
use App\Jobs\ProcessGdprRequest;
use App\Models\GdprRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GdprRequestController extends AdminController
{
    public function store(StoreGdprRequestRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $gdprRequest = GdprRequest::query()->create([
            'user_id' => $validated['user_id'],
            'type' => $validated['type'],
            'status' => GdprRequestStatus::Requested,
            'reason' => $validated['reason'] ?? null,
            'legal_hold' => $validated['legal_hold'] ?? false,
            'legal_hold_reason' => $validated['legal_hold_reason'] ?? null,
            'due_at' => $validated['due_at'] ?? null,
        ]);

        $this->audit(
            $request,
            'admin.gdpr_request.created',
            $gdprRequest,
            after: $this->snapshot($gdprRequest),
        );

        return back()->with('success', __('Die DSGVO-Anfrage wurde angelegt.'));
    }

    public function update(
        UpdateGdprRequestRequest $request,
        GdprRequest $gdprRequest,
    ): RedirectResponse {
        $validated = $request->validated();
        $status = GdprRequestStatus::from($validated['status']);
        $before = $this->snapshot($gdprRequest);
        $current = $gdprRequest->status;
        $actorId = $request->user()?->getKey();
        $this->assertTransition($gdprRequest, $status, $actorId);

        $updates = [
            'handled_by' => $request->user()?->getKey(),
            'type' => $gdprRequest->type,
            'status' => $status,
            'reason' => $validated['reason'] ?? null,
            'legal_hold' => $validated['legal_hold'] ?? $gdprRequest->legal_hold,
            'legal_hold_reason' => $validated['legal_hold_reason'] ?? null,
            'due_at' => $validated['due_at'] ?? null,
        ];
        if ($status === GdprRequestStatus::Verified) {
            $updates['verified_by'] = $actorId;
            $updates['verified_at'] = now();
        }
        if ($status === GdprRequestStatus::Processing) {
            $updates['approved_by'] = $actorId;
            $updates['processing_started_at'] = now();
            $updates['failed_at'] = null;
            $updates['failure_reason'] = null;
        }
        $gdprRequest->update($updates);

        $this->audit(
            $request,
            'admin.gdpr_request.updated',
            $gdprRequest,
            $before,
            $this->snapshot($gdprRequest),
        );

        if ($status === GdprRequestStatus::Processing) {
            ProcessGdprRequest::dispatch($gdprRequest->getKey())->afterCommit();
        }

        return back()->with('success', __('Die DSGVO-Anfrage wurde aktualisiert.'));
    }

    public function download(
        Request $request,
        GdprRequest $gdprRequest,
        AuditLogger $audit,
    ): StreamedResponse {
        abort_unless($gdprRequest->type === 'export', 404);
        abort_unless($gdprRequest->status === GdprRequestStatus::Completed, 409);
        abort_if($gdprRequest->downloaded_at !== null, 410);
        abort_if($gdprRequest->export_expires_at?->isPast() ?? true, 410);
        abort_if(blank($gdprRequest->export_disk) || blank($gdprRequest->export_path), 404);

        $encrypted = Storage::disk($gdprRequest->export_disk)->get($gdprRequest->export_path);
        $contents = Crypt::decryptString($encrypted);
        $gdprRequest->forceFill(['downloaded_at' => now()])->save();
        $audit->record(
            'admin.gdpr_request.export_downloaded',
            $gdprRequest,
            metadata: ['subject_user_id' => $gdprRequest->user_id],
            request: $request,
        );

        return response()->streamDownload(
            static function () use ($contents): void {
                echo $contents;
            },
            sprintf('erin-dsgvo-export-%d.json', $gdprRequest->getKey()),
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(GdprRequest $gdprRequest): array
    {
        return [
            'user_id' => $gdprRequest->user_id,
            'handled_by' => $gdprRequest->handled_by,
            'verified_by' => $gdprRequest->verified_by,
            'approved_by' => $gdprRequest->approved_by,
            'type' => $gdprRequest->type,
            'status' => $this->enumValue($gdprRequest->getAttribute('status')),
            'reason' => $gdprRequest->reason,
            'legal_hold' => $gdprRequest->legal_hold,
            'legal_hold_reason' => $gdprRequest->legal_hold_reason,
            'due_at' => $this->auditDate($gdprRequest->due_at),
            'verified_at' => $this->auditDate($gdprRequest->verified_at),
            'completed_at' => $this->auditDate($gdprRequest->completed_at),
        ];
    }

    private function assertTransition(
        GdprRequest $request,
        GdprRequestStatus $target,
        ?int $actorId,
    ): void {
        $allowed = match ($request->status) {
            GdprRequestStatus::Requested => [
                GdprRequestStatus::Verified,
                GdprRequestStatus::Rejected,
            ],
            GdprRequestStatus::Verified => [
                GdprRequestStatus::Processing,
                GdprRequestStatus::Rejected,
            ],
            GdprRequestStatus::Failed => [GdprRequestStatus::Processing],
            default => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => __('Dieser DSGVO-Statusübergang ist nicht zulässig.'),
            ]);
        }

        if (
            $target === GdprRequestStatus::Processing
            && ($request->verified_by === null || $request->verified_by === $actorId)
        ) {
            throw ValidationException::withMessages([
                'status' => __('Prüfung und Freigabe müssen durch zwei verschiedene Superadmins erfolgen.'),
            ]);
        }

        if (
            $target === GdprRequestStatus::Processing
            && $request->type === 'delete'
            && $request->legal_hold
        ) {
            throw ValidationException::withMessages([
                'status' => __('Die Löschung ist durch einen aktiven Legal Hold gesperrt.'),
            ]);
        }
    }
}
