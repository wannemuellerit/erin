<?php

namespace App\Policies;

use App\Enums\Capability;
use App\Enums\JobStatus;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\Authorization\CapabilityResolver;

class JobPostingPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, JobPosting $jobPosting): bool
    {
        return $jobPosting->status === JobStatus::Published
            || ($user?->isPlatformStaff() ?? false)
            || ($user?->belongsToCompany($jobPosting->company_id) ?? false);
    }

    public function create(User $user, Company $company): bool
    {
        return $user->isSuperAdmin()
            || app(CapabilityResolver::class)->allows($user, Capability::JobsManage, $company);
    }

    public function update(User $user, JobPosting $jobPosting): bool
    {
        return $this->create($user, $jobPosting->company);
    }

    public function delete(User $user, JobPosting $jobPosting): bool
    {
        return $user->isSuperAdmin()
            || app(CapabilityResolver::class)->allows(
                $user,
                Capability::CompanyManage,
                $jobPosting->company,
            );
    }
}
