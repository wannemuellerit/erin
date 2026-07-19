<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Models\CandidateProfile;
use App\Models\CandidateSavedSearch;
use App\Models\JobInvitation;
use App\Models\JobPosting;
use App\Models\Language;
use App\Models\Occupation;
use App\Models\Skill;
use App\Models\TalentList;
use App\Services\Companies\CurrentCompany;
use App\Services\Matching\CandidateMatchService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CandidateController extends Controller
{
    public function index(
        Request $request,
        CurrentCompany $currentCompany,
        CandidateMatchService $matching,
    ): Response {
        $company = $currentCompany->forRequest($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'country' => ['nullable', 'string', 'size:2'],
            'occupation' => ['nullable', 'integer', 'exists:occupations,id'],
            'experience' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'employment_type' => ['nullable', Rule::in(['full_time', 'part_time', 'temporary', 'permanent'])],
            'weekly_hours' => ['nullable', 'integer', 'min:1', 'max:80'],
            'skill' => ['nullable', 'integer', 'exists:skills,id'],
            'language' => ['nullable', 'string', 'max:8', 'exists:languages,code'],
            'language_level' => ['nullable', Rule::in(['A1', 'A2', 'B1', 'B2', 'C1', 'C2'])],
            'driving_license' => ['nullable', 'string', 'max:10'],
            'salary_max' => ['nullable', 'integer', 'min:0'],
            'available_before' => ['nullable', 'date'],
            'relocation_ready' => ['nullable', 'boolean'],
            'work_permit' => ['nullable', 'boolean'],
            'visa' => ['nullable', Rule::in(['required', 'not_required'])],
            'documents_complete' => ['nullable', 'boolean'],
            'view' => ['nullable', Rule::in(['all', 'favorites', 'ai'])],
            'job' => ['nullable', 'integer', 'required_if:view,ai'],
            'sort' => ['nullable', Rule::in(['published_desc', 'experience_desc', 'availability_asc', 'salary_asc'])],
            'per_page' => ['nullable', 'integer', Rule::in([12, 24, 48])],
        ]);
        $selectedJob = isset($filters['job'])
            ? $company->jobPostings()->with(['skills', 'languages'])->findOrFail((int) $filters['job'])
            : null;
        $favoriteCandidateIds = $company->talentLists()
            ->with('members:id,talent_list_id,candidate_profile_id')
            ->get()
            ->flatMap->members
            ->pluck('candidate_profile_id')
            ->unique()
            ->map(static fn ($id): int => (int) $id)
            ->values();

        $eagerLoads = [
            'occupation:id,slug,name_de,name_en',
            'skills:id,slug,name_de,name_en',
            'languages:id,code,name_de,name_en',
            'documents:id,candidate_profile_id,type,status',
        ];
        $hydrate = function (EloquentBuilder $query) use (
            $eagerLoads,
            $filters,
            $favoriteCandidateIds,
        ): EloquentBuilder {
            /** @var EloquentBuilder<CandidateProfile> $query */
            return $this->applyDatabaseFilters(
                $query->whereNotNull('published_at')->with($eagerLoads),
                $filters,
                $favoriteCandidateIds,
            );
        };
        $search = config('scout.driver') === 'collection'
            ? CandidateProfile::search(
                (string) ($filters['search'] ?? ''),
                $hydrate,
            )
            : CandidateProfile::search((string) ($filters['search'] ?? ''))
                ->query($hydrate);

        if (config('scout.driver') !== 'collection' && filled($filters['country'] ?? null)) {
            $search->where('current_country_code', mb_strtoupper((string) $filters['country']));
        }
        if (config('scout.driver') !== 'collection' && isset($filters['occupation'])) {
            $search->where('occupation_id', (int) $filters['occupation']);
        }
        if (config('scout.driver') !== 'collection' && isset($filters['experience'])) {
            $search->where('experience_years', '>=', (float) $filters['experience']);
        }
        if (config('scout.driver') !== 'collection' && filled($filters['employment_type'] ?? null)) {
            $search->where('employment_preferences', (string) $filters['employment_type']);
        }
        if (config('scout.driver') !== 'collection' && isset($filters['weekly_hours'])) {
            $search->where('weekly_hours', '>=', (int) $filters['weekly_hours']);
        }
        if (config('scout.driver') !== 'collection' && isset($filters['skill'])) {
            $search->where('skill_ids', (int) $filters['skill']);
        }
        if (config('scout.driver') !== 'collection' && filled($filters['language'] ?? null)) {
            if (filled($filters['language_level'] ?? null)) {
                $levels = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];
                $minimum = array_search($filters['language_level'], $levels, true);
                $search->whereIn('language_levels', collect($levels)
                    ->slice($minimum === false ? 0 : $minimum)
                    ->map(fn (string $level): string => "{$filters['language']}:{$level}")
                    ->values()
                    ->all());
            } else {
                $search->where('language_codes', (string) $filters['language']);
            }
        }
        if (config('scout.driver') !== 'collection' && filled($filters['driving_license'] ?? null)) {
            $search->where('driving_licenses', mb_strtoupper((string) $filters['driving_license']));
        }
        if (config('scout.driver') !== 'collection' && isset($filters['salary_max'])) {
            $search->where('salary_expectation_cents', '<=', (int) $filters['salary_max']);
        }
        if (config('scout.driver') !== 'collection' && filled($filters['available_before'] ?? null)) {
            $search->where('available_from_timestamp', '<=', Carbon::parse($filters['available_before'])->endOfDay()->timestamp);
        }
        if (config('scout.driver') !== 'collection' && ($filters['relocation_ready'] ?? false) === true) {
            $search->where('relocation_ready', true);
        }
        if (config('scout.driver') !== 'collection' && ($filters['work_permit'] ?? false) === true) {
            $search->where('has_work_permit', true);
        }
        if (config('scout.driver') !== 'collection' && ($filters['visa'] ?? null) === 'required') {
            $search->where('requires_visa', true);
        } elseif (config('scout.driver') !== 'collection' && ($filters['visa'] ?? null) === 'not_required') {
            $search->where('requires_visa', false);
        }
        if (config('scout.driver') !== 'collection' && ($filters['documents_complete'] ?? false) === true) {
            $search->where('profile_completeness', '>=', 80);
        }
        if (config('scout.driver') !== 'collection' && ($filters['view'] ?? 'all') === 'favorites') {
            $search->whereIn('id', $favoriteCandidateIds->all());
        }

        $sort = $filters['sort'] ?? 'published_desc';
        if (($filters['view'] ?? 'all') !== 'ai') {
            match ($sort) {
                'experience_desc' => $search->orderByDesc('experience_years'),
                'availability_asc' => $search->orderBy('available_from_timestamp'),
                'salary_asc' => $search->orderBy('salary_expectation_cents'),
                default => $search->orderByDesc('published_at'),
            };
        }

        $perPage = (int) ($filters['per_page'] ?? 24);
        if (($filters['view'] ?? 'all') === 'ai' && $selectedJob !== null) {
            $ranked = $search->take(1000)->get()
                ->map(fn (CandidateProfile $profile): array => $this->anonymized(
                    $profile,
                    $matching->for($profile, $selectedJob),
                    $favoriteCandidateIds->contains($profile->getKey()),
                ))
                ->sortByDesc('match.score')
                ->values();
            $page = max(1, $request->integer('page', 1));
            $candidates = new LengthAwarePaginator(
                $ranked->forPage($page, $perPage)->values(),
                $ranked->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );
        } else {
            /** @var LengthAwarePaginator<int, CandidateProfile> $paginated */
            $paginated = $search->paginate($perPage);
            $candidates = $paginated->through(
                fn (CandidateProfile $profile): array => $this->anonymized(
                    $profile,
                    $selectedJob ? $matching->for($profile, $selectedJob) : null,
                    $favoriteCandidateIds->contains($profile->getKey()),
                ),
            )->withQueryString();
        }

        return Inertia::render('employer/Candidates', [
            'candidates' => $candidates,
            'jobs' => $company->jobPostings()
                ->select(['id', 'title', 'status'])
                ->latest()
                ->get(),
            'occupations' => Occupation::query()->where('is_active', true)
                ->orderBy(app()->getLocale() === 'de' ? 'name_de' : 'name_en')
                ->get(['id', 'slug', 'name_de', 'name_en']),
            'skills' => Skill::query()->where('is_active', true)
                ->orderBy(app()->getLocale() === 'de' ? 'name_de' : 'name_en')
                ->get(['id', 'slug', 'name_de', 'name_en']),
            'languages' => Language::query()
                ->orderBy(app()->getLocale() === 'de' ? 'name_de' : 'name_en')
                ->get(['id', 'code', 'name_de', 'name_en']),
            'countries' => CandidateProfile::query()->published()
                ->whereNotNull('current_country_code')
                ->distinct()->orderBy('current_country_code')
                ->pluck('current_country_code'),
            'talent_lists' => $company->talentLists()->withCount('members')->get(),
            'saved_searches' => CandidateSavedSearch::query()
                ->where('company_id', $company->getKey())
                ->where('user_id', $request->user()?->getKey())
                ->latest()->get(['id', 'name', 'filters']),
            'filters' => $filters,
        ]);
    }

    public function show(
        Request $request,
        CandidateProfile $candidate,
        CurrentCompany $currentCompany,
        CandidateMatchService $matching,
    ): Response {
        abort_if($candidate->published_at === null, 404);
        $company = $currentCompany->forRequest($request);
        $candidate->load(['occupation', 'skills', 'languages', 'experiences', 'educations']);

        $application = $candidate->applications()
            ->whereHas('jobPosting', fn ($query) => $query->where('company_id', $company->getKey()))
            ->latest()
            ->first();
        $acceptedInvitation = $candidate->invitations()
            ->where('status', 'accepted')
            ->whereHas('jobPosting', fn ($query) => $query->where('company_id', $company->getKey()))
            ->exists();
        $identityVisible = $application?->identityIsRevealed() === true || $acceptedInvitation;
        $selectedJob = $request->integer('job')
            ? $company->jobPostings()->findOrFail($request->integer('job'))
            : null;

        return Inertia::render('employer/CandidateShow', [
            'candidate' => [
                ...$this->anonymized(
                    $candidate,
                    $selectedJob ? $matching->for($candidate, $selectedJob) : null,
                    false,
                ),
                'identity_revealed' => $identityVisible,
                'identity' => $identityVisible ? [
                    'first_name' => $candidate->first_name,
                    'last_name' => $candidate->last_name,
                    'email' => $candidate->user->email,
                    'city' => $candidate->current_city,
                    'phone' => $candidate->phone,
                    'whatsapp' => $candidate->whatsapp,
                ] : null,
                'experiences' => $candidate->experiences->map(fn ($experience) => [
                    'position' => $experience->position,
                    'country_code' => $experience->country_code,
                    'started_at' => $experience->started_at,
                    'ended_at' => $experience->ended_at,
                    'is_current' => $experience->is_current,
                    'description' => $experience->description,
                    'employer' => $identityVisible ? $experience->employer : __('Anonymisiertes Unternehmen'),
                ]),
                'educations' => $candidate->educations->map->only([
                    'qualification',
                    'field',
                    'country_code',
                    'completed_at',
                ]),
            ],
            'jobs' => $company->jobPostings()->select(['id', 'title', 'status'])->latest()->get(),
            'talent_lists' => $company->talentLists()->withCount('members')->get(),
        ]);
    }

    public function invite(
        Request $request,
        CandidateProfile $candidate,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        abort_if($candidate->published_at === null, 404);
        $company = $currentCompany->forRequest($request);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        $validated = $request->validate([
            'job_posting_id' => ['required', 'integer'],
            'message' => ['nullable', 'string', 'max:3000'],
        ]);
        /** @var JobPosting $job */
        $job = $company->jobPostings()->published()->findOrFail($validated['job_posting_id']);

        JobInvitation::query()->updateOrCreate(
            ['job_posting_id' => $job->getKey(), 'candidate_profile_id' => $candidate->getKey()],
            [
                'invited_by' => $request->user()?->getKey(),
                'status' => 'pending',
                'message' => $validated['message'] ?? null,
                'expires_at' => now()->addDays(14),
                'responded_at' => null,
            ],
        );

        return back()->with('success', __('Die Einladung wurde versendet.'));
    }

    public function saveToTalentList(
        Request $request,
        CandidateProfile $candidate,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        abort_if($candidate->published_at === null, 404);
        $company = $currentCompany->forRequest($request);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        $validated = $request->validate([
            'talent_list_id' => ['nullable', 'integer'],
            'list_name' => ['nullable', 'required_without:talent_list_id', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var TalentList $list */
        $list = isset($validated['talent_list_id'])
            ? $company->talentLists()->findOrFail($validated['talent_list_id'])
            : TalentList::query()->firstOrCreate(
                ['company_id' => $company->getKey(), 'name' => $validated['list_name']],
                ['created_by' => $request->user()?->getKey()],
            );

        $list->members()->updateOrCreate(
            ['candidate_profile_id' => $candidate->getKey()],
            ['added_by' => $request->user()?->getKey(), 'note' => $validated['note'] ?? null],
        );

        return back()->with('success', __('Die Fachkraft wurde im Talent-Pool gespeichert.'));
    }

    public function removeFromTalentList(
        Request $request,
        CandidateProfile $candidate,
        TalentList $talentList,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($talentList->company_id === $company->getKey(), 404);
        $talentList->members()->where('candidate_profile_id', $candidate->getKey())->delete();

        return back()->with('success', __('Die Fachkraft wurde aus der Liste entfernt.'));
    }

    public function storeSavedSearch(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->forRequest($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
            'filters.*' => ['nullable'],
        ]);
        CandidateSavedSearch::query()->updateOrCreate(
            [
                'company_id' => $company->getKey(),
                'user_id' => $request->user()?->getKey(),
                'name' => $validated['name'],
            ],
            ['filters' => $this->sanitizedSavedFilters($validated['filters'])],
        );

        return back()->with('success', __('Die Suche wurde gespeichert.'));
    }

    public function destroySavedSearch(
        Request $request,
        CandidateSavedSearch $savedSearch,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless(
            $savedSearch->company_id === $company->getKey()
            && $savedSearch->user_id === $request->user()?->getKey(),
            404,
        );
        $savedSearch->delete();

        return back()->with('success', __('Die gespeicherte Suche wurde gelöscht.'));
    }

    public function storeTalentList(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->forRequest($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $company->talentLists()->create([
            ...$validated,
            'created_by' => $request->user()?->getKey(),
        ]);

        return back()->with('success', __('Die Talent-Liste wurde erstellt.'));
    }

    public function updateTalentList(
        Request $request,
        TalentList $talentList,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($talentList->company_id === $company->getKey(), 404);
        $talentList->update($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]));

        return back()->with('success', __('Die Talent-Liste wurde aktualisiert.'));
    }

    public function destroyTalentList(
        Request $request,
        TalentList $talentList,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($talentList->company_id === $company->getKey(), 404);
        abort_if($talentList->is_default, 422, __('Die Standardliste kann nicht gelöscht werden.'));
        $talentList->delete();

        return back()->with('success', __('Die Talent-Liste wurde gelöscht.'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function sanitizedSavedFilters(array $filters): array
    {
        return Arr::only($filters, [
            'search', 'country', 'occupation', 'experience', 'employment_type',
            'weekly_hours', 'skill', 'language', 'language_level', 'driving_license',
            'salary_max', 'available_before', 'relocation_ready', 'work_permit',
            'visa', 'documents_complete', 'view', 'job', 'sort', 'per_page',
        ]);
    }

    /**
     * Collection Scout uses this query directly in tests; Meilisearch applies
     * the equivalent index filters before this tenant-safe hydration query.
     *
     * @param  EloquentBuilder<CandidateProfile>  $query
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, int>  $favoriteCandidateIds
     * @return EloquentBuilder<CandidateProfile>
     */
    private function applyDatabaseFilters(
        EloquentBuilder $query,
        array $filters,
        Collection $favoriteCandidateIds,
    ): EloquentBuilder {
        $levels = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];
        $minimum = array_search($filters['language_level'] ?? null, $levels, true);

        return $query
            ->when(filled($filters['country'] ?? null), fn ($builder) => $builder
                ->where('current_country_code', mb_strtoupper((string) $filters['country'])))
            ->when(isset($filters['occupation']), fn ($builder) => $builder
                ->where('occupation_id', (int) $filters['occupation']))
            ->when(isset($filters['experience']), fn ($builder) => $builder
                ->where('experience_years', '>=', (float) $filters['experience']))
            ->when(filled($filters['employment_type'] ?? null), fn ($builder) => $builder
                ->whereJsonContains('employment_preferences', (string) $filters['employment_type']))
            ->when(isset($filters['weekly_hours']), fn ($builder) => $builder
                ->where('weekly_hours', '>=', (int) $filters['weekly_hours']))
            ->when(isset($filters['skill']), fn ($builder) => $builder
                ->whereHas('skills', fn ($skills) => $skills->whereKey((int) $filters['skill'])))
            ->when(filled($filters['language'] ?? null), fn ($builder) => $builder
                ->whereHas('languages', fn ($languages) => $languages
                    ->where('code', (string) $filters['language'])
                    ->when($minimum !== false, fn ($languageQuery) => $languageQuery
                        ->whereIn('candidate_language.level', array_slice($levels, (int) $minimum)))))
            ->when(filled($filters['driving_license'] ?? null), fn ($builder) => $builder
                ->whereJsonContains('driving_licenses', mb_strtoupper((string) $filters['driving_license'])))
            ->when(isset($filters['salary_max']), fn ($builder) => $builder
                ->where('salary_expectation_cents', '<=', (int) $filters['salary_max']))
            ->when(filled($filters['available_before'] ?? null), fn ($builder) => $builder
                ->whereDate('available_from', '<=', (string) $filters['available_before']))
            ->when(($filters['relocation_ready'] ?? false) === true, fn ($builder) => $builder
                ->where('relocation_ready', true))
            ->when(($filters['work_permit'] ?? false) === true, fn ($builder) => $builder
                ->where('has_work_permit', true))
            ->when(($filters['visa'] ?? null) === 'required', fn ($builder) => $builder
                ->where('requires_visa', true))
            ->when(($filters['visa'] ?? null) === 'not_required', fn ($builder) => $builder
                ->where('requires_visa', false))
            ->when(($filters['documents_complete'] ?? false) === true, fn ($builder) => $builder
                ->where('completeness', '>=', 80))
            ->when(($filters['view'] ?? 'all') === 'favorites', fn ($builder) => $builder
                ->whereIn('id', $favoriteCandidateIds));
    }

    /**
     * @param  array{version: string, score: int, factors: array<string, mixed>}|null  $match
     * @return array<string, mixed>
     */
    private function anonymized(CandidateProfile $profile, ?array $match, bool $favorite): array
    {
        return [
            'id' => $profile->getKey(),
            'label' => $profile->anonymizedLabel(),
            'current_country_code' => $profile->current_country_code,
            'summary' => $profile->summary,
            'current_position' => $profile->current_position,
            'desired_position' => $profile->desired_position,
            'experience_years' => $profile->experience_years,
            'occupation' => $profile->occupation?->only(['id', 'slug', 'name_de', 'name_en']),
            'skills' => $profile->skills->map->only(['id', 'slug', 'name_de', 'name_en']),
            'languages' => $profile->languages->map(fn ($language) => [
                ...$language->only(['id', 'code', 'name_de', 'name_en']),
                'level' => $language->pivot->getAttribute('level'),
                'verified' => (bool) $language->pivot->getAttribute('is_verified'),
            ]),
            'experience' => $profile->experience_years,
            'available_from' => $profile->available_from?->toDateString(),
            'relocation_ready' => $profile->relocation_ready,
            'requires_visa' => $profile->requires_visa,
            'has_work_permit' => $profile->has_work_permit,
            'match' => $match,
            'favorite' => $favorite,
        ];
    }
}
