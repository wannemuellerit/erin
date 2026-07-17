<?php

namespace App\Policies;

use App\Models\CandidateProfile;
use App\Models\User;

class CandidateProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CandidateProfile $candidateProfile): bool
    {
        return $candidateProfile->user_id === $user->id
            || $user->isPlatformStaff()
            || $candidateProfile->published_at !== null;
    }

    public function viewIdentity(User $user, CandidateProfile $candidateProfile): bool
    {
        if ($candidateProfile->user_id === $user->id || $user->isPlatformStaff()) {
            return true;
        }

        $companyIds = $user->companyMemberships()
            ->whereNotNull('accepted_at')
            ->pluck('company_id');

        return $candidateProfile->applications()
            ->whereNotNull('identity_revealed_at')
            ->whereHas('jobPosting', fn ($query) => $query->whereIn('company_id', $companyIds))
            ->exists()
            || $candidateProfile->invitations()
                ->where('status', 'accepted')
                ->whereHas('jobPosting', fn ($query) => $query->whereIn('company_id', $companyIds))
                ->exists();
    }

    public function update(User $user, CandidateProfile $candidateProfile): bool
    {
        return $candidateProfile->user_id === $user->id || $user->isSuperAdmin();
    }

    public function delete(User $user, CandidateProfile $candidateProfile): bool
    {
        return $this->update($user, $candidateProfile);
    }
}
