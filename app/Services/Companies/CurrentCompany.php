<?php

namespace App\Services\Companies;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Http\Request;

class CurrentCompany
{
    public function forRequest(Request $request): Company
    {
        $companyId = $request->attributes->get('company_id')
            ?? $request->session()->get('active_company_id');

        $company = Company::query()->find((int) $companyId);
        abort_if($company === null, 404);

        return $company;
    }

    public function membership(Request $request): CompanyMembership
    {
        return CompanyMembership::query()
            ->where('company_id', $this->forRequest($request)->getKey())
            ->where('user_id', $request->user()?->getKey())
            ->whereNotNull('accepted_at')
            ->firstOrFail();
    }

    public function forUser(User $user): ?Company
    {
        return Company::query()
            ->whereHas('memberships', fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->whereNotNull('accepted_at'))
            ->first();
    }
}
