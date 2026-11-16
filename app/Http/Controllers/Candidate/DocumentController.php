<?php

namespace App\Http\Controllers\Candidate;

use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use App\Http\Controllers\Controller;
use App\Jobs\ScanCandidateDocument;
use App\Models\CandidateDocument;
use App\Services\Audit\AuditLogger;
use App\Services\Candidates\CandidateProfileStatus;
use App\Services\Documents\UploadPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    public function store(
        Request $request,
        UploadPolicy $uploads,
        CandidateProfileStatus $profileStatus,
        AuditLogger $audit,
    ): RedirectResponse {
        $validated = $request->validate($this->rules($uploads, true));
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $user = $request->user();
        abort_if($user === null, 401);
        $profile = $user->candidateProfile()->firstOrFail();
        $uploads->assertCanStore($user, $file);
        $document = $this->storeDocument($profile->getKey(), $validated, $file);

        $profileStatus->calculate($profile);
        $audit->record('candidate.document_uploaded', $document, after: $this->auditData($document));

        return back()->with('success', __('Dokument wurde hochgeladen und wird auf Schadsoftware geprüft.'));
    }

    public function update(
        Request $request,
        CandidateDocument $document,
        CandidateProfileStatus $profileStatus,
        AuditLogger $audit,
    ): RedirectResponse {
        $this->assertOwner($request, $document);
        $validated = $request->validate([
            'type' => ['required', Rule::enum(CandidateDocumentType::class)],
            'title' => ['required', 'string', 'max:180'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $before = $this->auditData($document);
        $trustRelevantChanged = $document->type->value !== $validated['type']
            || optional($document->expires_at)->toDateString() !== ($validated['expires_at'] ?? null);
        $document->update([
            ...$validated,
            ...($trustRelevantChanged ? [
                'status' => CandidateDocumentStatus::Uploaded,
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
                'shared_with_employers' => false,
            ] : []),
        ]);
        if ($trustRelevantChanged) {
            $this->revokeAll($document);
        }

        $profileStatus->calculate($document->candidateProfile);
        $audit->record('candidate.document_updated', $document, $before, $this->auditData($document));

        return back()->with('success', __('Dokumentdaten wurden aktualisiert.'));
    }

    public function replace(
        Request $request,
        CandidateDocument $document,
        UploadPolicy $uploads,
        CandidateProfileStatus $profileStatus,
        AuditLogger $audit,
    ): RedirectResponse {
        $this->assertOwner($request, $document);
        $validated = $request->validate($this->rules($uploads, true));
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $user = $request->user();
        abort_if($user === null, 401);
        $uploads->assertCanStore($user, $file, 'file', (int) ($document->size_bytes ?? 0));
        $path = $file->store("candidates/{$document->candidate_profile_id}/documents", 'private');
        abort_if($path === false, 500, __('Das Dokument konnte nicht privat gespeichert werden.'));
        $before = $this->auditData($document);
        $oldDisk = $document->disk;
        $oldPath = $document->path;

        try {
            DB::transaction(function () use ($document, $validated, $file, $path): void {
                $document->update([
                    'type' => $validated['type'],
                    'title' => $validated['title'],
                    'expires_at' => $validated['expires_at'] ?? null,
                    'disk' => 'private',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'sha256' => hash_file('sha256', (string) $file->getRealPath()),
                    'status' => CandidateDocumentStatus::Uploaded,
                    'scan_result' => 'pending',
                    'scan_completed_at' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                    'rejection_reason' => null,
                    'shared_with_employers' => false,
                    'version' => $document->version + 1,
                    'replaced_at' => now(),
                ]);
                $this->revokeAll($document);
                ScanCandidateDocument::dispatch($document->getKey())->afterCommit();
            });
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        Storage::disk($oldDisk)->delete($oldPath);
        $profileStatus->calculate($document->candidateProfile);
        $audit->record('candidate.document_replaced', $document, $before, $this->auditData($document));

        return back()->with('success', __('Neue Dokumentversion wurde hochgeladen und wird geprüft.'));
    }

    public function destroy(
        Request $request,
        CandidateDocument $document,
        CandidateProfileStatus $profileStatus,
        AuditLogger $audit,
    ): RedirectResponse {
        $this->assertOwner($request, $document);
        $before = $this->auditData($document);
        $disk = $document->disk;
        $path = $document->path;
        DB::transaction(function () use ($document): void {
            $this->revokeAll($document);
            $document->delete();
        });
        Storage::disk($disk)->delete($path);
        $profileStatus->calculate($document->candidateProfile);
        $audit->record('candidate.document_deleted', $document, before: $before);

        return back()->with('success', __('Dokument wurde sicher gelöscht.'));
    }

    /** @return array<string, mixed> */
    private function rules(UploadPolicy $uploads, bool $requireFile): array
    {
        return [
            'type' => ['required', Rule::enum(CandidateDocumentType::class)],
            'title' => ['required', 'string', 'max:180'],
            'file' => [$requireFile ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:'.$uploads->maxFileKilobytes(15360)],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function storeDocument(int $profileId, array $validated, UploadedFile $file): CandidateDocument
    {
        $path = $file->store("candidates/{$profileId}/documents", 'private');
        abort_if($path === false, 500, __('Das Dokument konnte nicht privat gespeichert werden.'));
        try {
            $document = CandidateDocument::query()->create([
                'candidate_profile_id' => $profileId,
                'type' => $validated['type'],
                'title' => $validated['title'],
                'disk' => 'private',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', (string) $file->getRealPath()),
                'status' => CandidateDocumentStatus::Uploaded,
                'scan_result' => 'pending',
                'expires_at' => $validated['expires_at'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
        ScanCandidateDocument::dispatch($document->getKey());

        return $document;
    }

    private function assertOwner(Request $request, CandidateDocument $document): void
    {
        abort_unless($document->candidateProfile->user_id === $request->user()?->getKey(), 404);
    }

    private function revokeAll(CandidateDocument $document): void
    {
        $document->grants()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function auditData(CandidateDocument $document): array
    {
        return [
            'type' => $document->type->value,
            'title' => $document->title,
            'status' => $document->status->value,
            'scan_result' => $document->scan_result,
            'sha256' => $document->sha256,
            'version' => $document->version,
            'expires_at' => $document->expires_at?->toIso8601String(),
        ];
    }
}
