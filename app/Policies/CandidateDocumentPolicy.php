<?php

namespace App\Policies;

use App\Models\CandidateDocument;
use App\Models\User;

class CandidateDocumentPolicy
{
    public function view(User $user, CandidateDocument $candidateDocument): bool
    {
        if ($candidateDocument->candidateProfile->user_id === $user->id || $user->isPlatformStaff()) {
            return true;
        }

        if (! $candidateDocument->isAvailableForSharing()) {
            return false;
        }

        $companyIds = $user->companyMemberships()
            ->whereNotNull('accepted_at')
            ->pluck('company_id');

        return $candidateDocument->candidateProfile->applications()
            ->whereNotNull('documents_shared_at')
            ->whereHas('jobPosting', fn ($query) => $query->whereIn('company_id', $companyIds))
            ->exists();
    }

    public function update(User $user, CandidateDocument $candidateDocument): bool
    {
        return $candidateDocument->candidateProfile->user_id === $user->id
            || $user->isSuperAdmin();
    }

    public function verify(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
