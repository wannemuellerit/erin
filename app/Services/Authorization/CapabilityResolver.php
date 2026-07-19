<?php

namespace App\Services\Authorization;

use App\Enums\Capability;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Http\Request;

final class CapabilityResolver
{
    /**
     * @return list<string>
     */
    public function forRequest(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        $companyId = (int) (
            $request->attributes->get('company_id')
            ?? $request->session()->get('active_company_id')
        );

        $membership = $companyId > 0
            ? $user->companyMemberships()
                ->where('company_id', $companyId)
                ->whereNotNull('accepted_at')
                ->first()
            : null;

        if ($membership === null && $companyId === 0 && $user->role === UserRole::Company) {
            $memberships = $user->companyMemberships()
                ->whereNotNull('accepted_at')
                ->limit(2)
                ->get();

            // Shared routes such as AI and interviews are not nested below the
            // employer prefix. A single company membership is therefore an
            // unambiguous capability context even before a session was chosen.
            $membership = $memberships->count() === 1 ? $memberships->first() : null;
        }

        return $this->for($user, $membership);
    }

    /**
     * @return list<string>
     */
    public function for(User $user, ?CompanyMembership $membership = null): array
    {
        if ($user->role === UserRole::SuperAdmin) {
            return array_map(
                static fn (Capability $capability): string => $capability->value,
                Capability::cases(),
            );
        }

        if ($user->role === UserRole::Support) {
            if ($user->platformRole?->is_active) {
                return array_values(array_unique([
                    Capability::DashboardView->value,
                    ...array_values(array_intersect(
                        (array) $user->platformRole->capabilities,
                        [
                            Capability::PlatformView->value,
                            Capability::PlatformSupportManage->value,
                            Capability::SupportUse->value,
                        ],
                    )),
                ]));
            }

            return [
                Capability::DashboardView->value,
                Capability::PlatformView->value,
                Capability::PlatformSupportManage->value,
                Capability::SupportUse->value,
            ];
        }

        if ($user->role === UserRole::Candidate) {
            return [
                Capability::DashboardView->value,
                Capability::CandidateProfileManage->value,
                Capability::CandidateJobsView->value,
                Capability::CandidateApplicationsManage->value,
                Capability::MessagesView->value,
                Capability::MessagesManage->value,
                Capability::InterviewsView->value,
                Capability::InterviewsManage->value,
                Capability::ReferralsView->value,
                Capability::ReferralsManage->value,
                Capability::CandidateAiUse->value,
                Capability::SupportUse->value,
            ];
        }

        if ($membership === null) {
            return [];
        }

        $view = [
            Capability::DashboardView,
            Capability::CandidateMarketplaceView,
            Capability::JobsView,
            Capability::ApplicationsView,
            Capability::MessagesView,
            Capability::InterviewsView,
            Capability::AnalyticsView,
            Capability::VisaView,
            Capability::ReferralsView,
            Capability::CompanyView,
            Capability::TeamView,
            Capability::BillingView,
            Capability::SupportUse,
        ];
        $recruiting = [
            Capability::CandidateMarketplaceManage,
            Capability::JobsManage,
            Capability::ApplicationsManage,
            Capability::MessagesManage,
            Capability::InterviewsManage,
            Capability::ProductivityManage,
            Capability::VisaManage,
            Capability::ReferralsManage,
            Capability::RecruitingAiUse,
        ];
        $management = [
            Capability::CompanyManage,
            Capability::TeamManage,
        ];
        $owner = [
            Capability::OwnershipTransfer,
            Capability::BillingManage,
        ];

        $capabilities = $view;
        if ($membership->role !== CompanyMemberRole::Viewer) {
            $capabilities = [...$capabilities, ...$recruiting];
        }
        if (in_array($membership->role, [CompanyMemberRole::Owner, CompanyMemberRole::Admin], true)) {
            $capabilities = [...$capabilities, ...$management];
        }
        if ($membership->role === CompanyMemberRole::Owner) {
            $capabilities = [...$capabilities, ...$owner];
        }

        return array_values(array_unique(array_map(
            static fn (Capability $capability): string => $capability->value,
            $capabilities,
        )));
    }

    public function allows(
        User $user,
        Capability|string $capability,
        Company|CompanyMembership|null $context = null,
    ): bool {
        $membership = $context instanceof CompanyMembership
            ? $context
            : ($context instanceof Company
                ? $user->companyMemberships()
                    ->where('company_id', $context->getKey())
                    ->whereNotNull('accepted_at')
                    ->first()
                : null);
        $value = $capability instanceof Capability ? $capability->value : $capability;

        return in_array($value, $this->for($user, $membership), true);
    }
}
