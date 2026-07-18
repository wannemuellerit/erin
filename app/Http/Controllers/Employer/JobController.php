<?php

namespace App\Http\Controllers\Employer;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ScanJobMedia;
use App\Models\Company;
use App\Models\JobMedia;
use App\Models\JobPosting;
use App\Models\Language;
use App\Models\Occupation;
use App\Models\Skill;
use App\Services\Activity\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\EntitlementService;
use App\Services\Companies\CurrentCompany;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class JobController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, EntitlementService $entitlements): Response
    {
        $company = $currentCompany->forRequest($request);

        return Inertia::render('employer/Jobs', [
            'jobs' => $company->jobPostings()
                ->with(['location:id,name,city', 'occupation:id,name_de,name_en'])
                ->withCount('applications')
                ->latest()
                ->get(),
            'entitlements' => $entitlements->summary($company),
        ]);
    }

    public function create(Request $request, CurrentCompany $currentCompany): Response
    {
        $this->assertCanRecruit($request, $currentCompany);

        return Inertia::render('employer/JobForm', [
            'job' => null,
            ...$this->formOptions($currentCompany->forRequest($request)),
        ]);
    }

    public function store(
        Request $request,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertCanRecruit($request, $currentCompany);
        $validated = $this->validateJob($request);

        $job = DB::transaction(function () use ($request, $company, $validated): JobPosting {
            $job = $company->jobPostings()->create([
                ...Arr::except($validated, ['skills', 'languages', 'screening_questions', 'media']),
                'created_by' => $request->user()?->getKey(),
                'slug' => $this->uniqueSlug($company->getKey(), $validated['title']),
                'status' => JobStatus::Draft,
            ]);

            $this->syncRelations($job, $validated, $request);

            return $job;
        });

        $audit->record('job.created', $job, after: $job->toArray(), companyId: $company->getKey());
        $activity->record(
            'job.created',
            $request->user(),
            $company,
            $job,
            ['job_title' => $job->title],
        );

        return redirect()->route('employer.jobs.edit', $job)->with('success', __('Stellenanzeige wurde als Entwurf gespeichert.'));
    }

    public function edit(
        Request $request,
        JobPosting $job,
        CurrentCompany $currentCompany,
    ): Response {
        $company = $currentCompany->forRequest($request);
        $this->assertOwned($job, $company->getKey());
        $this->assertCanRecruit($request, $currentCompany);
        $job->load(['skills', 'languages', 'screeningQuestions', 'media']);

        return Inertia::render('employer/JobForm', [
            'job' => [
                ...Arr::except($job->toArray(), ['media']),
                'media' => $job->media->map(
                    fn (JobMedia $media): array => [
                        'id' => $media->getKey(),
                        'type' => $media->type,
                        'original_name' => $media->original_name,
                        'mime_type' => $media->mime_type,
                        'size_bytes' => $media->size_bytes,
                        'scan_result' => $media->scan_result,
                        'download_url' => $media->scan_result === 'clean'
                            ? URL::temporarySignedRoute(
                                'jobs.media.download',
                                now()->addMinutes(15),
                                ['media' => $media],
                            )
                            : null,
                    ],
                )->values(),
            ],
            ...$this->formOptions($company),
        ]);
    }

    public function update(
        Request $request,
        JobPosting $job,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertOwned($job, $company->getKey());
        $this->assertCanRecruit($request, $currentCompany);
        $validated = $this->validateJob($request);
        $before = $job->toArray();

        DB::transaction(function () use ($job, $validated, $request): void {
            $job->update(Arr::except($validated, ['skills', 'languages', 'screening_questions', 'media']));
            $this->syncRelations($job, $validated, $request);
        });

        $audit->record('job.updated', $job, $before, $job->fresh()->toArray(), companyId: $company->getKey());

        return back()->with('success', __('Stellenanzeige wurde aktualisiert.'));
    }

    public function transition(
        Request $request,
        JobPosting $job,
        CurrentCompany $currentCompany,
        EntitlementService $entitlements,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertOwned($job, $company->getKey());
        $this->assertCanRecruit($request, $currentCompany);
        $validated = $request->validate(['status' => ['required', Rule::enum(JobStatus::class)]]);
        $target = JobStatus::from($validated['status']);
        $allowed = [
            JobStatus::Draft->value => [JobStatus::Published, JobStatus::Archived],
            JobStatus::Published->value => [JobStatus::Paused, JobStatus::Filled, JobStatus::Archived],
            JobStatus::Paused->value => [JobStatus::Published, JobStatus::Filled, JobStatus::Archived],
            JobStatus::Filled->value => [JobStatus::Archived],
            JobStatus::Archived->value => [],
        ];
        $before = $job->status;

        try {
            DB::transaction(function () use ($company, $job, $target, $allowed, $entitlements): void {
                Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
                $job->refresh();
                abort_unless(
                    in_array($target, $allowed[$job->status->value], true),
                    422,
                    __('Dieser Statuswechsel ist nicht erlaubt.'),
                );

                if ($target === JobStatus::Published) {
                    $entitlements->assertCanPublishJob($company, $job);
                }

                $job->update([
                    'status' => $target,
                    'published_at' => $target === JobStatus::Published ? ($job->published_at ?? now()) : $job->published_at,
                    'closed_at' => in_array($target, [JobStatus::Filled, JobStatus::Archived], true) ? now() : null,
                ]);
            }, 3);
        } catch (DomainException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        $audit->record(
            'job.status_changed',
            $job,
            ['status' => $before->value],
            ['status' => $target->value],
            companyId: $company->getKey(),
        );

        return back()->with('success', __('Der Stellenstatus wurde geändert.'));
    }

    public function boost(
        Request $request,
        JobPosting $job,
        CurrentCompany $currentCompany,
        EntitlementService $entitlements,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertOwned($job, $company->getKey());
        $this->assertCanRecruit($request, $currentCompany);
        abort_unless($job->status === JobStatus::Published, 422, __('Nur veröffentlichte Stellen können geboostet werden.'));

        try {
            DB::transaction(function () use ($company, $job, $entitlements): void {
                $entitlements->consumeBoost($company);
                $job->update(['boosted_until' => now()->addDay()]);
            }, 3);
        } catch (DomainException $exception) {
            return back()->withErrors(['boost' => $exception->getMessage()]);
        }

        $audit->record('job.boosted', $job, after: [
            'boosted_until' => $job->boosted_until?->toIso8601String(),
        ], companyId: $company->getKey());

        return back()->with('success', __('Die Stellenanzeige wird 24 Stunden hervorgehoben.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateJob(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'position' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:50000'],
            'occupation_id' => ['nullable', 'exists:occupations,id'],
            'location_id' => ['nullable', 'exists:company_locations,id'],
            'expected_experience_years' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'language_notes' => ['nullable', 'string', 'max:3000'],
            'hours_min' => ['nullable', 'integer', 'min:1', 'max:80'],
            'hours_max' => ['nullable', 'integer', 'gte:hours_min', 'max:80'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'temporary', 'permanent'])],
            'compensation_min_cents' => ['nullable', 'integer', 'min:0'],
            'compensation_max_cents' => ['nullable', 'integer', 'gte:compensation_min_cents'],
            'currency' => ['required', Rule::in(['EUR'])],
            'compensation_interval' => ['required', Rule::in(['hour', 'month', 'year'])],
            'is_remote' => ['boolean'],
            'visa_package_available' => ['boolean'],
            'skills' => ['array'],
            'skills.*.id' => ['required', 'exists:skills,id'],
            'skills.*.importance' => ['nullable', 'integer', 'min:1', 'max:5'],
            'skills.*.minimum_experience_years' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'languages' => ['array'],
            'languages.*.id' => ['required', 'exists:languages,id'],
            'languages.*.minimum_level' => ['required', Rule::in(['A1', 'A2', 'B1', 'B2', 'C1', 'C2'])],
            'languages.*.is_required' => ['boolean'],
            'screening_questions' => ['array', 'max:5'],
            'screening_questions.*.question' => ['required', 'string', 'max:500'],
            'screening_questions.*.type' => ['required', Rule::in(['text', 'yes_no', 'choice'])],
            'screening_questions.*.is_required' => ['boolean'],
            'screening_questions.*.options' => ['nullable', 'array', 'max:10'],
            'media' => ['array', 'max:10'],
            'media.*' => ['file', 'mimes:jpg,jpeg,png,gif,pdf,doc,docx', 'max:10240'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function syncRelations(JobPosting $job, array $validated, Request $request): void
    {
        /** @var list<array{id: int, importance?: int, minimum_experience_years?: float|int|null}> $skills */
        $skills = is_array($validated['skills'] ?? null) ? array_values($validated['skills']) : [];
        $skillSync = [];
        foreach ($skills as $skill) {
            $skillSync[$skill['id']] = [
                'importance' => $skill['importance'] ?? 1,
                'minimum_experience_years' => $skill['minimum_experience_years'] ?? null,
            ];
        }
        $job->skills()->sync($skillSync);
        /** @var list<array{id: int, minimum_level: string, is_required?: bool}> $languages */
        $languages = is_array($validated['languages'] ?? null) ? array_values($validated['languages']) : [];
        $languageSync = [];
        foreach ($languages as $language) {
            $languageSync[$language['id']] = [
                'minimum_level' => $language['minimum_level'],
                'is_required' => $language['is_required'] ?? true,
            ];
        }
        $job->languages()->sync($languageSync);
        $job->screeningQuestions()->delete();
        foreach ($validated['screening_questions'] ?? [] as $index => $question) {
            $job->screeningQuestions()->create([...$question, 'sort_order' => $index]);
        }

        foreach ($request->file('media', []) as $file) {
            $path = $file->store("companies/{$job->company_id}/jobs/{$job->getKey()}", 'private');
            abort_if($path === false, 500);
            $media = $job->media()->create([
                'type' => str_starts_with((string) $file->getMimeType(), 'image/') ? 'image' : 'document',
                'disk' => 'private',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'scan_result' => 'pending',
            ]);
            ScanJobMedia::dispatch($media->getKey())->afterCommit();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Company $company): array
    {
        return [
            'occupations' => Occupation::query()->where('is_active', true)->orderBy('name_de')->get(),
            'skills' => Skill::query()->where('is_active', true)->orderBy('name_de')->get(),
            'languages' => Language::query()->orderBy('name_de')->get(),
            'locations' => $company->locations()->orderBy('name')->get(),
        ];
    }

    private function assertCanRecruit(Request $request, CurrentCompany $currentCompany): void
    {
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
    }

    private function assertOwned(JobPosting $job, int $companyId): void
    {
        abort_unless($job->company_id === $companyId, 404);
    }

    private function uniqueSlug(int $companyId, string $title): string
    {
        $base = Str::slug($title) ?: 'stelle';
        $slug = $base;
        $suffix = 2;

        while (JobPosting::query()->withTrashed()->where('company_id', $companyId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
