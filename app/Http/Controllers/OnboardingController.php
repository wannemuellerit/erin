<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Occupation;
use App\Models\Plan;
use App\Services\Candidates\ProfileCompletenessCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        if ($user->isPlatformStaff() || $user->onboarding_completed_at !== null) {
            return redirect()->route('dashboard');
        }

        $profile = $user->role === UserRole::Candidate
            ? $user->candidateProfile()->first()
            : null;
        $company = $user->role === UserRole::Company
            ? $user->companies()->wherePivotNotNull('accepted_at')->first()
            : null;

        return Inertia::render('Onboarding', [
            'role' => $user->role->value,
            'candidate_profile' => $profile?->only([
                'first_name',
                'last_name',
                'occupation_id',
                'current_country_code',
                'current_city',
                'phone',
                'summary',
                'desired_position',
                'experience_years',
                'relocation_ready',
                'requires_visa',
                'has_work_permit',
            ]),
            'company' => $company?->only([
                'id',
                'name',
                'legal_name',
                'email',
                'website',
                'industry',
                'employee_count',
                'country_code',
                'city',
                'postal_code',
                'address_line1',
                'current_plan_id',
            ]),
            'occupations' => Occupation::query()
                ->where('is_active', true)
                ->orderBy('name_de')
                ->get(['id', 'slug', 'name_de', 'name_en']),
            'plans' => Plan::query()
                ->where('is_active', true)
                ->where('is_enterprise', false)
                ->orderBy('price_cents')
                ->get([
                    'id',
                    'slug',
                    'name',
                    'description',
                    'price_cents',
                    'currency',
                    'term_months',
                    'active_jobs_limit',
                    'seat_limit',
                    'ai_credits_monthly',
                    'job_boosts_per_term',
                    'visa_credits_per_term',
                ]),
        ]);
    }

    public function candidate(
        Request $request,
        ProfileCompletenessCalculator $completeness,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user?->role === UserRole::Candidate, 403);
        $profile = $user->candidateProfile()->firstOrFail();
        $validated = $request->validate([
            'occupation_id' => ['required', Rule::exists('occupations', 'id')->where('is_active', true)],
            'current_country_code' => ['required', 'string', 'size:2'],
            'current_city' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'summary' => ['required', 'string', 'min:80', 'max:5000'],
            'desired_position' => ['required', 'string', 'max:180'],
            'experience_years' => ['required', 'numeric', 'min:0', 'max:60'],
            'relocation_ready' => ['required', 'boolean'],
            'requires_visa' => ['required', 'boolean'],
            'has_work_permit' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($user, $profile, $validated, $completeness): void {
            $profile->update([
                ...$validated,
                'current_country_code' => mb_strtoupper($validated['current_country_code']),
            ]);
            $profile->refresh();
            $status = $completeness->calculate([
                ...$profile->toArray(),
                'work_experiences_count' => $profile->experiences()->count(),
                'skills_count' => $profile->skills()->count(),
                'languages_count' => $profile->languages()->count(),
                'educations_count' => $profile->educations()->count(),
                'has_cv' => false,
                'has_verified_certificate' => false,
            ]);
            $profile->updateQuietly(['completeness' => $status['percentage']]);
            $user->forceFill(['onboarding_completed_at' => now()])->save();
        });

        return redirect()->route('candidate.profile')->with(
            'success',
            __('Dein Einstieg ist abgeschlossen. Ergänze jetzt Skills, Sprachen und Dokumente.'),
        );
    }

    public function company(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->role === UserRole::Company, 403);
        $company = $user->companies()->wherePivotNotNull('accepted_at')->firstOrFail();
        $validated = $request->validate([
            'plan_slug' => [
                'required',
                Rule::exists('plans', 'slug')->where(
                    fn ($query) => $query->where('is_active', true)->where('is_enterprise', false),
                ),
            ],
            'legal_name' => ['required', 'string', 'max:180'],
            'email' => ['required', 'email', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'industry' => ['required', 'string', 'max:120'],
            'employee_count' => ['required', 'integer', 'min:1', 'max:1000000'],
            'country_code' => ['required', 'string', 'size:2'],
            'city' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:20'],
            'address_line1' => ['required', 'string', 'max:180'],
        ]);
        $plan = Plan::query()->where('slug', $validated['plan_slug'])->firstOrFail();

        DB::transaction(function () use ($user, $company, $validated, $plan): void {
            $company->update([
                ...Arr::except($validated, ['plan_slug']),
                'current_plan_id' => $plan->getKey(),
                'country_code' => mb_strtoupper($validated['country_code']),
            ]);
            $user->forceFill(['onboarding_completed_at' => now()])->save();
        });

        return redirect()->route('employer.billing')->with(
            'success',
            __('Firmendaten gespeichert. Prüfe die Angaben und starte anschließend die sichere Stripe-Zahlung.'),
        );
    }
}
