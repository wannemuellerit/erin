<?php

namespace App\Policies;

use App\Enums\CompanyMemberRole;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;

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
            || $user->hasCompanyRole($company, [
                CompanyMemberRole::Owner,
                CompanyMemberRole::Admin,
            ]);
    }

    public function manageMembers(User $user, Company $company): bool
    {
        return $this->update($user, $company);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->isSuperAdmin()
            || $user->hasCompanyRole($company, [CompanyMemberRole::Owner]);
    }
}
