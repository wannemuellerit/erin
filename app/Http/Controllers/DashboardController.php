<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Companies\CurrentCompany;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentCompany $currentCompany,
        EntitlementService $entitlements,
        PlatformSettings $settings,
    ): Response|RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 401);

        if (
            in_array($user->role, [UserRole::Candidate, UserRole::Company], true)
            && $user->onboarding_completed_at === null
        ) {
            return redirect()->route('onboarding.show');
        }

        if ($user->role === UserRole::Candidate) {
            $profile = $user->candidateProfile()
                ->with(['documents', 'skills', 'languages'])
                ->first();

            if ($profile === null) {
                return Inertia::render('Dashboard', [
                    'dashboard' => [
                        'kind' => 'candidate',
                        'requires_profile' => true,
                        'profile_completeness' => 0,
                        'can_apply' => false,
                        'matching_jobs' => 0,
                        'active_applications' => 0,
                        'upcoming_interviews' => 0,
                        'missing_documents' => 3,
                        'latest_applications' => [],
                    ],
                ]);
            }

            return Inertia::render('Dashboard', [
                'dashboard' => [
                    'kind' => 'candidate',
                    'profile_completeness' => $profile->completeness,
                    'can_apply' => $profile->canApply(),
                    'matching_jobs' => $profile->occupation_id
                        ? JobPosting::published()->where('occupation_id', $profile->occupation_id)->count()
                        : JobPosting::published()->count(),
                    'active_applications' => $profile->applications()->whereNotIn('status', [
                        ApplicationStatus::Rejected,
                        ApplicationStatus::Withdrawn,
                        ApplicationStatus::Hired,
                    ])->count(),
                    'upcoming_interviews' => Interview::query()
                        ->whereHas('application', fn ($query) => $query->where('candidate_profile_id', $profile->getKey()))
                        ->where('starts_at', '>=', now())
                        ->count(),
                    'missing_documents' => max(0, 3 - $profile->documents()
                        ->whereIn('type', ['cv', 'passport', 'qualification'])
                        ->count()),
                    'latest_applications' => $profile->applications()
                        ->with('jobPosting.company:id,name')
                        ->latest('applied_at')
                        ->limit(5)
                        ->get(),
                ],
            ]);
        }

        if ($user->role === UserRole::Company) {
            $company = $currentCompany->forUser($user);

            if ($company !== null && ! $entitlements->hasPortalAccess($company)) {
                return redirect()->route('employer.billing')->with(
                    'warning',
                    __('Wähle zuerst dein Firmenpaket und schließe die Zahlung ab.'),
                );
            }

            return Inertia::render('Dashboard', [
                'dashboard' => $company ? [
                    'kind' => 'company',
                    'company' => $company->only(['id', 'name', 'status', 'subscription_status']),
                    'entitlements' => $entitlements->summary($company),
                    'active_jobs' => $company->jobPostings()->where('status', JobStatus::Published)->count(),
                    'new_applications' => JobApplication::query()
                        ->whereHas('jobPosting', fn ($query) => $query->where('company_id', $company->getKey()))
                        ->where('status', ApplicationStatus::New)
                        ->count(),
                    'open_interviews' => Interview::query()
                        ->whereHas('application.jobPosting', fn ($query) => $query->where('company_id', $company->getKey()))
                        ->where('starts_at', '>=', now())
                        ->count(),
                    'dashboard_notice' => $this->dashboardNotice($settings, $user->locale),
                    'recent_applications' => JobApplication::query()
                        ->whereHas('jobPosting', fn ($query) => $query->where('company_id', $company->getKey()))
                        ->with(['jobPosting:id,title', 'candidateProfile:id,current_position,current_country_code'])
                        ->latest('applied_at')
                        ->limit(6)
                        ->get(),
                ] : ['kind' => 'company', 'requires_company' => true],
            ]);
        }

        return Inertia::render('Dashboard', [
            'dashboard' => [
                'kind' => 'admin',
                'users' => User::query()->count(),
                'candidates' => CandidateProfile::query()->count(),
                'companies' => Company::query()->count(),
                'documents_in_review' => CandidateDocument::query()->where('status', 'in_review')->count(),
                'open_tickets' => SupportTicket::query()->whereNotIn('status', ['resolved', 'closed'])->count(),
                'applications' => JobApplication::query()->count(),
            ],
        ]);
    }

    /**
     * @return array{enabled: bool, title: string, body: string, url: string|null}
     */
    private function dashboardNotice(PlatformSettings $settings, string $locale): array
    {
        $notice = $settings->get('dashboard.barmer_notice', [
            'enabled' => true,
            'title_de' => 'Gemeinsam gut ankommen',
            'title_en' => 'Arrive well together',
            'body_de' => 'Nach erfolgreichem Visumspaket unterstützen wir bei der Anmeldung der neuen Mitarbeitenden bei der BARMER.',
            'body_en' => 'After a successful visa package, we support the registration of new employees with BARMER.',
            'url' => null,
        ]);
        $language = $locale === 'en' ? 'en' : 'de';

        return [
            'enabled' => (bool) ($notice['enabled'] ?? true),
            'title' => (string) ($notice['title_'.$language] ?? ''),
            'body' => (string) ($notice['body_'.$language] ?? ''),
            'url' => is_string($notice['url'] ?? null) ? $notice['url'] : null,
        ];
    }
}
