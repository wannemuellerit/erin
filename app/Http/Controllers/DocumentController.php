<?php

namespace App\Http\Controllers;

use App\Enums\CandidateDocumentStatus;
use App\Models\CandidateDocument;
use App\Models\JobApplication;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\CandidateDocumentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function grant(
        Request $request,
        CandidateDocument $document,
        JobApplication $application,
        AuditLogger $audit,
    ): RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($document->candidateProfile->user_id === $user->getKey(), 403);
        abort_unless($application->candidate_profile_id === $document->candidate_profile_id, 422);
        abort_unless($document->status === CandidateDocumentStatus::Verified && $document->scan_result === 'clean', 422);
        $expiresAt = now()->addDays(7);

        DB::table('document_access_grants')->updateOrInsert(
            [
                'candidate_document_id' => $document->getKey(),
                'company_id' => $application->jobPosting->company_id,
                'application_id' => $application->getKey(),
            ],
            [
                'granted_by' => $user->getKey(),
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $application->update(['documents_shared_at' => now()]);
        $audit->record('candidate.document_access_granted', $document, after: [
            'company_id' => $application->jobPosting->company_id,
            'application_id' => $application->getKey(),
            'expires_at' => $expiresAt->toIso8601String(),
        ], companyId: $application->jobPosting->company_id);

        return back()->with('success', __('Das Dokument ist sieben Tage für dieses Unternehmen freigegeben.'));
    }

    public function download(
        Request $request,
        CandidateDocument $document,
        AuditLogger $audit,
        CandidateDocumentAccess $access,
    ): StreamedResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_if($document->scan_result !== 'clean', 423, __('Das Dokument ist noch nicht sicherheitsgeprüft.'));
        abort_unless($access->canDownload($user, $document), 403);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);
        $ownsDocument = $document->candidateProfile->user_id === $user->getKey();
        $companyId = $access->grantedCompanyId($user, $document);
        $audit->record('candidate.document_downloaded', $document, metadata: [
            'owner_access' => $ownsDocument,
            'superadmin_access' => $user->isSuperAdmin(),
            'grant_company_id' => $companyId,
        ], companyId: $companyId);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }
}
