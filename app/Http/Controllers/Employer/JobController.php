<?php

namespace App\Http\Controllers\Employer;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employer\UpsertJobPostingRequest;
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
use App\Services\Documents\UploadPolicy;
use App\Services\Jobs\JobPublishingReadiness;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        UpsertJobPostingRequest $request,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertCanRecruit($request, $currentCompany);
        $validated = $request->validated();
        $this->assertLocationBelongsToCompany($company, $validated['location_id'] ?? null);
        $this->assertUploadQuota($request);

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
        UpsertJobPostingRequest $request,
        JobPosting $job,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertOwned($job, $company->getKey());
        $this->assertCanRecruit($request, $currentCompany);
        $validated = $request->validated();
        $this->assertLocationBelongsToCompany($company, $validated['location_id'] ?? null);
        if ($job->media()->count() + count($request->file('media', [])) > 10) {
            throw ValidationException::withMessages(['media' => __('Eine Stellenanzeige darf höchstens zehn Dateien enthalten.')]);
        }
        $this->assertScreeningQuestionsMutable($job, $validated['screening_questions'] ?? []);
        $this->assertUploadQuota($request);
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
        JobPublishingReadiness $readiness,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertOwned($job, $company->getKey());
        $this->assertCanRecruit($request, $currentCompany);
        $validated = $request->validate(['status' => ['required', Rule::enum(JobStatus::class)]]);
        $target = JobStatus::from($validated['status']);
        if ($target === JobStatus::Published && ($missing = $readiness->missing($job)) !== []) {
            return back()->withErrors(['status' => __('Vor der Veröffentlichung fehlen: :fields.', [
                'fields' => implode(', ', $missing),
            ])]);
        }
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

    public function duplicate(
        Request $request,
        JobPosting $job,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertOwned($job, $company->getKey());
        $this->assertCanRecruit($request, $currentCompany);
        $job->load(['skills', 'languages', 'screeningQuestions']);
        $copy = DB::transaction(function () use ($job, $request, $company): JobPosting {
            $copy = $job->replicate([
                'slug', 'status', 'published_at', 'closed_at', 'boosted_until',
            ]);
            $copy->title = __('Kopie von :title', ['title' => $job->title]);
            $copy->slug = $this->uniqueSlug($company->getKey(), $copy->title);
            $copy->status = JobStatus::Draft;
            $copy->created_by = $request->user()?->getKey();
            $copy->published_at = null;
            $copy->closed_at = null;
            $copy->boosted_until = null;
            $copy->save();
            $copy->skills()->sync(DB::table('job_skill')->where('job_posting_id', $job->getKey())
                ->get()->mapWithKeys(fn (object $row): array => [(int) $row->skill_id => [
                    'importance' => $row->importance,
                    'minimum_experience_years' => $row->minimum_experience_years,
                ]])->all());
            $copy->languages()->sync(DB::table('job_language')->where('job_posting_id', $job->getKey())
                ->get()->mapWithKeys(fn (object $row): array => [(int) $row->language_id => [
                    'minimum_level' => $row->minimum_level,
                    'is_required' => $row->is_required,
                ]])->all());
            foreach ($job->screeningQuestions as $question) {
                $copy->screeningQuestions()->create($question->only([
                    'question', 'type', 'is_required', 'sort_order', 'options',
                ]));
            }

            return $copy;
        });
        $audit->record('job.duplicated', $copy, after: [
            'source_job_id' => $job->getKey(),
        ], companyId: $company->getKey());

        return redirect()->route('employer.jobs.edit', $copy)
            ->with('success', __('Die Stellenanzeige wurde als neuer Entwurf dupliziert.'));
    }

    public function destroy(
        Request $request,
        JobPosting $job,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertOwned($job, $company->getKey());
        $this->assertCanRecruit($request, $currentCompany);
        abort_unless(in_array($job->status, [JobStatus::Draft, JobStatus::Archived], true), 422, __('Nur Entwürfe und archivierte Stellen können gelöscht werden.'));
        abort_if($job->applications()->exists() || $job->invitations()->exists(), 422, __('Stellen mit Bewerbungen oder Einladungen müssen aus Nachweisgründen archiviert bleiben.'));
        $media = $job->media()->get(['disk', 'path']);
        $before = $job->toArray();
        $job->delete();
        foreach ($media as $file) {
            Storage::disk($file->disk)->delete($file->path);
        }
        $audit->record('job.deleted', $job, before: $before, companyId: $company->getKey());

        return redirect()->route('employer.jobs.index')->with('success', __('Die Stellenanzeige wurde gelöscht.'));
    }

    public function destroyMedia(
        Request $request,
        JobPosting $job,
        JobMedia $media,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        $this->assertOwned($job, $company->getKey());
        $this->assertCanRecruit($request, $currentCompany);
        abort_unless($media->job_posting_id === $job->getKey(), 404);
        $before = $media->toArray();
        $media->delete();
        Storage::disk($media->disk)->delete($media->path);
        $audit->record('job.media_deleted', $media, before: $before, companyId: $company->getKey());

        return back()->with('success', __('Die Datei wurde aus der Stellenanzeige entfernt.'));
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
        if (! $job->applications()->exists()) {
            $job->screeningQuestions()->delete();
            foreach ($validated['screening_questions'] ?? [] as $index => $question) {
                $job->screeningQuestions()->create([...$question, 'sort_order' => $index]);
            }
        }

        $storedPaths = [];
        try {
            foreach ($request->file('media', []) as $file) {
                $path = $file->store("companies/{$job->company_id}/jobs/{$job->getKey()}", 'private');
                abort_if($path === false, 500);
                $storedPaths[] = $path;
                $media = $job->media()->create([
                    'uploaded_by' => $request->user()?->getKey(),
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
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($storedPaths);
            throw $exception;
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

    private function assertUploadQuota(Request $request): void
    {
        $files = $request->file('media', []);
        if (! is_array($files) || $files === []) {
            return;
        }

        $user = $request->user();
        abort_if($user === null, 401);
        app(UploadPolicy::class)->assertCanStore($user, array_values($files), 'media');
    }

    private function assertOwned(JobPosting $job, int $companyId): void
    {
        abort_unless($job->company_id === $companyId, 404);
    }

    private function assertLocationBelongsToCompany(Company $company, ?int $locationId): void
    {
        if ($locationId !== null && ! $company->locations()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages([
                'location_id' => __('Der ausgewählte Standort gehört nicht zu diesem Unternehmen.'),
            ]);
        }
    }

    /** @param list<array<string, mixed>> $submitted */
    private function assertScreeningQuestionsMutable(JobPosting $job, array $submitted): void
    {
        if (! $job->applications()->exists()) {
            return;
        }
        $current = $job->screeningQuestions()->get()->map(fn ($question): array => [
            'question' => $question->question,
            'type' => $question->type,
            'is_required' => (bool) $question->is_required,
            'options' => array_values($question->options ?? []),
        ])->values()->all();
        $next = collect($submitted)->map(fn (array $question): array => [
            'question' => $question['question'],
            'type' => $question['type'],
            'is_required' => (bool) ($question['is_required'] ?? false),
            'options' => array_values($question['options'] ?? []),
        ])->values()->all();
        if ($current !== $next) {
            throw ValidationException::withMessages([
                'screening_questions' => __('Screening-Fragen können nach der ersten Bewerbung nicht mehr verändert werden, da Antworten revisionssicher erhalten bleiben müssen.'),
            ]);
        }
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
