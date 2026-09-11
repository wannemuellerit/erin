<?php

namespace App\Services\Partners;

use App\Enums\UserRole;
use App\Models\PartnerCase;
use App\Models\PartnerMember;
use App\Models\User;

final class PartnerCaseAccess
{
    public function canView(User $user, PartnerCase $case): bool
    {
        return match ($user->role) {
            UserRole::Candidate => $case->candidate_user_id === $user->getKey(),
            UserRole::Company => $case->company_id !== null && $user->belongsToCompany($case->company_id),
            UserRole::Partner => $this->assignedPartnerMember($user, $case) !== null && $case->hasActiveConsent(),
            UserRole::Support, UserRole::SuperAdmin => true,
        };
    }

    public function canManage(User $user, PartnerCase $case): bool
    {
        if ($user->role === UserRole::Candidate) {
            return $case->candidate_user_id === $user->getKey();
        }
        if ($user->role === UserRole::SuperAdmin) {
            return true;
        }
        $member = $this->assignedPartnerMember($user, $case);

        return $member !== null && $case->hasActiveConsent()
            && in_array('partner.cases.manage', (array) $member->capabilities, true);
    }

    public function assignedPartnerMember(User $user, PartnerCase $case): ?PartnerMember
    {
        if ($user->role !== UserRole::Partner || $case->partner_organization_id === null) {
            return null;
        }

        return PartnerMember::query()
            ->where('user_id', $user->getKey())
            ->where('partner_organization_id', $case->partner_organization_id)
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->whereHas('organization', fn ($query) => $query->whereNull('blocked_at'))
            ->where(function ($query) use ($case): void {
                $query->where('id', $case->assigned_member_id)->orWhere('role', 'admin');
            })->first();
    }
}
