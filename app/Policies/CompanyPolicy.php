<?php

namespace App\Policies;

use App\Enums\Capability;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use App\Services\Authorization\CapabilityResolver;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Company $company): bool
    {
        return $company->status === CompanyStatus::Active
            || $user->isPlatformStaff()
            || $user->belongsToCompany($company);
    }

    public function update(User $user, Company $company): bool
    {
        return $user->isSuperAdmin()
            || app(CapabilityResolver::class)->allows($user, Capability::CompanyManage, $company);
    }

    public function manageMembers(User $user, Company $company): bool
    {
        return $this->update($user, $company);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->isSuperAdmin()
            || app(CapabilityResolver::class)->allows($user, Capability::OwnershipTransfer, $company);
    }
}
