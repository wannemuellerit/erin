<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Models\CandidateProfile;
use App\Models\JobInvitation;
use App\Models\JobPosting;
use App\Models\Occupation;
use App\Models\TalentList;
use App\Services\Companies\CurrentCompany;
use App\Services\Matching\CandidateMatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $selectedJob = $request->integer('job')
            ? $company->jobPostings()->with(['skills', 'languages'])->findOrFail($request->integer('job'))
            : null;

        $query = CandidateProfile::query()
            ->published()
            ->with([
                'occupation:id,slug,name_de,name_en',
                'skills:id,slug,name_de,name_en',
                'languages:id,code,name_de,name_en',
                'documents:id,candidate_profile_id,type,status',
            ])
            ->when($request->filled('search'), function ($builder) use ($request): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->string('search')).'%';
                $builder->where(fn ($query) => $query
                    ->where('desired_position', 'like', $term)
                    ->orWhere('current_position', 'like', $term)
                    ->orWhere('summary', 'like', $term)
                    ->orWhereHas('skills', fn ($skillQuery) => $skillQuery
                        ->where('name_de', 'like', $term)
                        ->orWhere('name_en', 'like', $term)));
            })
            ->when($request->filled('country'), fn ($builder) => $builder
                ->where('current_country_code', strtoupper((string) $request->string('country'))))
            ->when($request->integer('occupation'), fn ($builder) => $builder
                ->where('occupation_id', $request->integer('occupation')))
            ->when($request->filled('experience'), fn ($builder) => $builder
                ->where('experience_years', '>=', $request->integer('experience')))
            ->when($request->boolean('relocation_ready'), fn ($builder) => $builder->where('relocation_ready', true))
            ->when($request->boolean('work_permit'), fn ($builder) => $builder->where('has_work_permit', true))
            ->when($request->filled('employment_type'), fn ($builder) => $builder
                ->whereJsonContains('employment_preferences', (string) $request->string('employment_type')))
            ->when($request->integer('skill'), fn ($builder) => $builder
                ->whereHas('skills', fn ($skillQuery) => $skillQuery->where('skills.id', $request->integer('skill'))))
            ->limit(100);

        $favoriteCandidateIds = $company->talentLists()
            ->with('members:id,talent_list_id,candidate_profile_id')
            ->get()
            ->flatMap->members
            ->pluck('candidate_profile_id')
            ->unique();

        if ($request->string('view')->toString() === 'favorites') {
            $query->whereIn('id', $favoriteCandidateIds);
        }

        $candidates = $query->get()
            ->map(fn (CandidateProfile $profile): array => $this->anonymized(
                $profile,
                $selectedJob ? $matching->for($profile, $selectedJob) : null,
                $favoriteCandidateIds->contains($profile->getKey()),
            ))
            ->when(
                $request->string('view')->toString() === 'ai' && $selectedJob,
                fn (Collection $items) => $items->sortByDesc('match.score')->values(),
            )
            ->values();

        return Inertia::render('employer/Candidates', [
            'candidates' => $candidates,
            'jobs' => $company->jobPostings()
                ->select(['id', 'title', 'status'])
                ->latest()
                ->get(),
            'occupations' => Occupation::query()->where('is_active', true)
                ->orderBy(app()->getLocale() === 'de' ? 'name_de' : 'name_en')
                ->get(['id', 'slug', 'name_de', 'name_en']),
            'talent_lists' => $company->talentLists()->withCount('members')->get(),
            'filters' => $request->only([
                'search',
                'country',
                'occupation',
                'experience',
                'employment_type',
                'skill',
                'relocation_ready',
                'work_permit',
                'view',
                'job',
            ]),
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

    /**
     * @param  array{score: int, factors: array<string, mixed>}|null  $match
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
