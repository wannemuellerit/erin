<?php

namespace App\Services\Documents;

use App\Enums\CandidateDocumentStatus;
use App\Models\CandidateDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CandidateDocumentAccess
{
    public function canDownload(User $user, CandidateDocument $document): bool
    {
        if ($document->scan_result !== 'clean') {
            return false;
        }

        if ($document->candidateProfile->user_id === $user->getKey()) {
            return true;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (
            $document->status !== CandidateDocumentStatus::Verified
            || $user->role->value !== 'company'
        ) {
            return false;
        }

        return $this->activeGrantFor($user, $document) !== null;
    }

    public function grantedCompanyId(User $user, CandidateDocument $document): ?int
    {
        return $this->activeGrantFor($user, $document);
    }

    private function activeGrantFor(User $user, CandidateDocument $document): ?int
    {
        $companyId = DB::table('document_access_grants as grants')
            ->join('company_memberships as memberships', function ($join) use ($user): void {
                $join
                    ->on('memberships.company_id', '=', 'grants.company_id')
                    ->where('memberships.user_id', $user->getKey())
                    ->whereNotNull('memberships.accepted_at');
            })
            ->join('applications', 'applications.id', '=', 'grants.application_id')
            ->join('job_postings', 'job_postings.id', '=', 'applications.job_posting_id')
            ->where('grants.candidate_document_id', $document->getKey())
            ->whereColumn('job_postings.company_id', 'grants.company_id')
            ->where('applications.candidate_profile_id', $document->candidate_profile_id)
            ->whereNull('grants.revoked_at')
            ->where('grants.expires_at', '>', now())
            ->value('grants.company_id');

        return $companyId === null ? null : (int) $companyId;
    }
}
