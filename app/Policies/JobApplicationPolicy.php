<?php

namespace App\Policies;

use App\Enums\Capability;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\Authorization\CapabilityResolver;

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
            || app(CapabilityResolver::class)->allows(
                $user,
                Capability::ApplicationsManage,
                $application->jobPosting->company,
            );
    }

    public function withdraw(User $user, JobApplication $application): bool
    {
        return $application->candidateProfile->user_id === $user->id
            && ! $application->status->isTerminal();
    }
}
