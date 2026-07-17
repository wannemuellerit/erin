<?php

namespace App\Policies;

use App\Enums\CompanyMemberRole;
use App\Models\JobApplication;
use App\Models\User;

class JobApplicationPolicy
{
    public function view(User $user, JobApplication $application): bool
    {
        return $application->candidateProfile->user_id === $user->id
            || $user->isPlatformStaff()
            || $user->belongsToCompany($application->jobPosting->company_id);
    }

    public function manage(User $user, JobApplication $application): bool
    {
        return $user->isSuperAdmin()
            || $user->hasCompanyRole($application->jobPosting->company, [
                CompanyMemberRole::Owner,
                CompanyMemberRole::Admin,
                CompanyMemberRole::Recruiter,
            ]);
    }

    public function withdraw(User $user, JobApplication $application): bool
    {
        return $application->candidateProfile->user_id === $user->id
            && ! $application->status->isTerminal();
    }
}
