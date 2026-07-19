<?php

namespace App\Http\Controllers;

use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\Language;
use App\Models\Occupation;
use App\Models\Plan;
use App\Models\Skill;
use App\Models\User;
use App\Services\Candidates\ProfileCompletenessCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            ? $user->candidateProfile()->with(['experiences', 'educations', 'skills', 'languages'])->first()
            : null;
        $company = $user->role === UserRole::Company
            ? $user->companies()->wherePivotNotNull('accepted_at')->first()
            : null;

        return Inertia::render('Onboarding', [
            'role' => $user->role->value,
            'onboarding' => [
                'current_step' => max(2, (int) ($user->onboarding_step ?? 2)),
                'total_steps' => $user->role === UserRole::Candidate ? 7 : 6,
                'saved_data' => $user->onboarding_data ?? [],
            ],
            'publication_threshold' => app(ProfileCompletenessCalculator::class)->threshold(),
            'candidate_profile' => $profile ? [
                ...$profile->only([
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
                    'current_position',
                    'whatsapp',
                ]),
                'experiences' => $profile->experiences->map->only([
                    'employer', 'position', 'country_code', 'started_at',
                    'ended_at', 'is_current', 'description',
                ])->values(),
                'educations' => $profile->educations->map->only([
                    'institution', 'qualification', 'field', 'country_code',
                    'started_at', 'completed_at',
                ])->values(),
                'skills' => $profile->skills->map(fn (Skill $skill): array => ['id' => $skill->getKey()])->values(),
                'languages' => $profile->languages->map(fn (Language $language): array => [
                    'id' => $language->getKey(),
                    'level' => $language->pivot->getAttribute('level'),
                ])->values(),
                'availability' => $user->availabilitySlots()
                    ->orderBy('weekday')
                    ->orderBy('starts_at')
                    ->get(['weekday', 'starts_at', 'ends_at', 'timezone']),
            ] : null,
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
            'skills' => Skill::query()->where('is_active', true)->orderBy('name_de')
                ->get(['id', 'slug', 'name_de', 'name_en']),
            'languages' => Language::query()->orderBy('name_de')
                ->get(['id', 'code', 'name_de', 'name_en']),
            'document_types' => collect(CandidateDocumentType::cases())->map->value,
        ]);
    }

    public function candidateStep(
        Request $request,
        int $step,
        ProfileCompletenessCalculator $completeness,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user?->role === UserRole::Candidate, 403);
        $this->assertReachableStep($user, $step, 7);
        $profile = $user->candidateProfile()->firstOrFail();

        $validated = match ($step) {
            2 => $request->validate([
                'first_name' => ['required', 'string', 'max:120'],
                'last_name' => ['required', 'string', 'max:120'],
                'current_country_code' => ['required', 'string', 'size:2'],
                'current_city' => ['required', 'string', 'max:120'],
                'phone' => ['required', 'string', 'max:40'],
                'whatsapp' => ['nullable', 'string', 'max:40'],
            ]),
            3 => $request->validate([
                'occupation_id' => ['required', Rule::exists('occupations', 'id')->where('is_active', true)],
                'current_position' => ['nullable', 'string', 'max:180'],
                'desired_position' => ['required', 'string', 'max:180'],
                'experience_years' => ['required', 'numeric', 'min:0', 'max:60'],
                'summary' => ['required', 'string', 'min:80', 'max:5000'],
                'relocation_ready' => ['required', 'boolean'],
                'requires_visa' => ['required', 'boolean'],
                'has_work_permit' => ['required', 'boolean'],
            ]),
            4 => $request->validate([
                'experiences' => ['required', 'array', 'min:1', 'max:20'],
                'experiences.*.employer' => ['required', 'string', 'max:180'],
                'experiences.*.position' => ['required', 'string', 'max:180'],
                'experiences.*.country_code' => ['nullable', 'string', 'size:2'],
                'experiences.*.started_at' => ['required', 'date'],
                'experiences.*.ended_at' => ['nullable', 'date', 'after_or_equal:experiences.*.started_at'],
                'experiences.*.is_current' => ['required', 'boolean'],
                'experiences.*.description' => ['nullable', 'string', 'max:3000'],
                'educations' => ['array', 'max:20'],
                'educations.*.institution' => ['required', 'string', 'max:180'],
                'educations.*.qualification' => ['required', 'string', 'max:180'],
                'educations.*.field' => ['nullable', 'string', 'max:180'],
                'educations.*.country_code' => ['nullable', 'string', 'size:2'],
                'educations.*.started_at' => ['nullable', 'date'],
                'educations.*.completed_at' => ['nullable', 'date'],
            ]),
            5 => $request->validate([
                'skills' => ['required', 'array', 'min:1'],
                'skills.*.id' => ['required', Rule::exists('skills', 'id')->where('is_active', true)],
                'languages' => ['required', 'array', 'min:1'],
                'languages.*.id' => ['required', 'exists:languages,id'],
                'languages.*.level' => ['required', Rule::in(['A1', 'A2', 'B1', 'B2', 'C1', 'C2'])],
            ]),
            6 => $request->validate(['acknowledged_private_uploads' => ['accepted']]),
            7 => $request->validate([
                'availability' => ['required', 'array', 'min:1', 'max:28'],
                'availability.*.weekday' => ['required', 'integer', 'between:1,7'],
                'availability.*.starts_at' => ['required', 'date_format:H:i'],
                'availability.*.ends_at' => ['required', 'date_format:H:i'],
                'availability.*.timezone' => ['required', 'timezone'],
                'publish_profile' => ['required', 'boolean'],
            ]),
            default => abort(404),
        };
        if ($step === 7) {
            $this->assertAvailabilityDoesNotOverlap($validated['availability']);
        }

        DB::transaction(function () use ($user, $profile, $step, $validated, $completeness): void {
            if (in_array($step, [2, 3], true)) {
                $profile->update([
                    ...$validated,
                    ...($step === 2 ? [
                        'current_country_code' => mb_strtoupper($validated['current_country_code']),
                    ] : []),
                ]);
            } elseif ($step === 4) {
                $profile->experiences()->delete();
                $profile->experiences()->createMany($validated['experiences']);
                $profile->educations()->delete();
                $profile->educations()->createMany($validated['educations'] ?? []);
            } elseif ($step === 5) {
                /** @var list<array{id: int}> $skills */
                $skills = $validated['skills'];
                /** @var list<array{id: int, level: string}> $languages */
                $languages = $validated['languages'];
                $profile->skills()->sync(collect($skills)->mapWithKeys(
                    fn (array $skill): array => [(int) $skill['id'] => ['is_verified' => false]],
                )->all());
                $profile->languages()->sync(collect($languages)->mapWithKeys(
                    fn (array $language): array => [(int) $language['id'] => [
                        'level' => $language['level'],
                        'is_verified' => false,
                    ]],
                )->all());
            } elseif ($step === 7) {
                $user->availabilitySlots()->delete();
                $user->availabilitySlots()->createMany($validated['availability']);
            }

            $saved = $user->onboarding_data ?? [];
            $saved['completed_steps'] = array_values(array_unique([
                ...((array) ($saved['completed_steps'] ?? [])),
                $step,
            ]));
            $nextStep = min(7, max((int) $user->onboarding_step, $step + 1));
            $user->forceFill([
                'onboarding_step' => $nextStep,
                'onboarding_data' => $saved,
            ])->save();

            if ($step === 7) {
                $status = $this->profileStatus($profile, $completeness);
                if ($validated['publish_profile']) {
                    abort_if(! $status['can_apply'], 422, __('Für die Veröffentlichung müssen mindestens :percentage % des Profils vollständig sein.', [
                        'percentage' => $status['required_percentage'],
                    ]));
                    $profile->update(['published_at' => now()]);
                }
                $user->forceFill(['onboarding_completed_at' => now()])->save();
            }
        });

        return $step === 7
            ? redirect()->route('candidate.profile')->with('success', __('Dein Einstieg ist abgeschlossen.'))
            : back()->with('success', __('Schritt gespeichert. Du kannst jederzeit hier fortfahren.'));
    }

    public function companyStep(Request $request, int $step): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->role === UserRole::Company, 403);
        $this->assertReachableStep($user, $step, 3);
        $company = $user->companies()->wherePivotNotNull('accepted_at')->firstOrFail();

        if ($step === 2) {
            $validated = $request->validate([
                'plan_slug' => [
                    'required',
                    Rule::exists('plans', 'slug')->where(
                        fn ($query) => $query->where('is_active', true)->where('is_enterprise', false),
                    ),
                ],
            ]);
            $plan = Plan::query()->where('slug', $validated['plan_slug'])->firstOrFail();
            $company->update(['current_plan_id' => $plan->getKey()]);
        } elseif ($step === 3) {
            $validated = $request->validate([
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
            $company->update([
                ...$validated,
                'country_code' => mb_strtoupper($validated['country_code']),
            ]);
        } else {
            abort(404);
        }

        $saved = $user->onboarding_data ?? [];
        $saved['completed_steps'] = array_values(array_unique([
            ...((array) ($saved['completed_steps'] ?? [])),
            $step,
        ]));
        $user->forceFill([
            'onboarding_step' => min(4, max((int) $user->onboarding_step, $step + 1)),
            'onboarding_data' => $saved,
            'onboarding_completed_at' => $step === 3 ? now() : null,
        ])->save();

        return $step === 3
            ? redirect()->route('employer.billing')->with('success', __('Firmendaten gespeichert. Starte jetzt den Stripe-Checkout.'))
            : back()->with('success', __('Paket gespeichert.'));
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

    private function assertReachableStep(User $user, int $step, int $maximum): void
    {
        abort_unless($step >= 2 && $step <= $maximum, 404);
        abort_if($step > max(2, (int) ($user->onboarding_step ?? 2)), 422, __('Dieser Schritt ist noch nicht freigeschaltet.'));
    }

    /**
     * @param  list<array{weekday: int, starts_at: string, ends_at: string, timezone: string}>  $slots
     */
    private function assertAvailabilityDoesNotOverlap(array $slots): void
    {
        $byDay = collect($slots)->groupBy('weekday');

        foreach ($byDay as $daySlots) {
            $ordered = $daySlots->sortBy('starts_at')->values();
            foreach ($ordered as $index => $slot) {
                if ($slot['starts_at'] >= $slot['ends_at']) {
                    throw ValidationException::withMessages([
                        'availability' => __('Das Ende muss nach dem Beginn liegen.'),
                    ]);
                }
                $next = $ordered->get($index + 1);
                if ($next !== null && $slot['ends_at'] > $next['starts_at']) {
                    throw ValidationException::withMessages([
                        'availability' => __('Verfügbarkeiten dürfen sich nicht überschneiden.'),
                    ]);
                }
            }
        }
    }

    /**
     * @return array{percentage: int, completed: list<string>, missing: list<string>, can_apply: bool, required_percentage: int}
     */
    private function profileStatus(
        CandidateProfile $profile,
        ProfileCompletenessCalculator $completeness,
    ): array {
        $profile->loadCount(['experiences', 'skills', 'languages', 'educations']);
        $profile->load('documents');

        return $completeness->calculate([
            ...$profile->toArray(),
            'work_experiences_count' => $profile->experiences_count,
            'skills_count' => $profile->skills_count,
            'languages_count' => $profile->languages_count,
            'educations_count' => $profile->educations_count,
            'has_cv' => $profile->documents->contains('type', CandidateDocumentType::Cv),
            'has_verified_certificate' => $profile->documents->contains(
                fn ($document): bool => $document->status === CandidateDocumentStatus::Verified,
            ),
        ]);
    }
}
