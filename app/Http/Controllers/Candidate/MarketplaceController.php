<?php

namespace App\Http\Controllers\Candidate;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\ReferralStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyMedia;
use App\Models\JobApplication;
use App\Models\JobInvitation;
use App\Models\JobPosting;
use App\Models\Referral;
use App\Notifications\ActivityNotification;
use App\Services\Activity\ActivityRecorder;
use App\Services\Applications\ApplicationWorkflow;
use App\Services\Audit\AuditLogger;
use App\Services\Matching\CandidateMatchService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceController extends Controller
{
    public function jobs(Request $request, CandidateMatchService $matching): Response
    {
        $profile = $request->user()?->candidateProfile()
            ->with(['skills', 'languages', 'documents'])
            ->firstOrFail();
        $appliedJobIds = $profile->applications()->pluck('job_posting_id');
        $jobs = JobPosting::query()
            ->published()
            ->with([
                'company:id,name,slug,industry,city,logo_media_id,benefits,description,last_active_at',
                'company.logoMedia:id,company_id,type,disk,path,original_name,mime_type,size_bytes,scan_result',
                'location:id,name,city,country_code',
                'occupation:id,slug,name_de,name_en',
                'skills:id,slug,name_de,name_en',
                'languages:id,code,name_de,name_en',
                'screeningQuestions',
            ])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->string('search')).'%';
                $query->where(fn ($builder) => $builder
                    ->where('title', 'like', $term)
                    ->orWhere('position', 'like', $term)
                    ->orWhere('description', 'like', $term));
            })
            ->when($request->filled('employment_type'), fn ($query) => $query
                ->where('employment_type', (string) $request->string('employment_type')))
            ->when($request->boolean('visa_support'), fn ($query) => $query->where('visa_package_available', true))
            ->when($request->boolean('remote'), fn ($query) => $query->where('is_remote', true))
            ->orderByDesc('boosted_until')
            ->latest('published_at')
            ->limit(100)
            ->get()
            ->map(function (JobPosting $job) use ($matching, $profile, $appliedJobIds): array {
                $data = $job->toArray();
                $data['company'] = $this->serializeCompany($job->company);

                return [
                    ...$data,
                    'match' => $matching->for($profile, $job),
                    'already_applied' => $appliedJobIds->contains($job->getKey()),
                ];
            })
            ->sortByDesc('match.score')
            ->values();

        return Inertia::render('candidate/Jobs', [
            'jobs' => $jobs,
            'filters' => $request->only(['search', 'employment_type', 'visa_support', 'remote']),
            'can_apply' => $profile->canApply(),
            'profile_completeness' => $profile->completeness,
        ]);
    }

    public function apply(
        Request $request,
        JobPosting $job,
        CandidateMatchService $matching,
        AuditLogger $audit,
        ActivityRecorder $activity,
    ): RedirectResponse {
        abort_unless($job->status === JobStatus::Published && $job->published_at !== null, 404);
        $profile = $request->user()?->candidateProfile()
            ->with(['skills', 'languages', 'documents'])
            ->firstOrFail();

        if (! $profile->canApply()) {
            return back()->withErrors([
                'profile' => __('Dein Profil muss veröffentlicht und zu mindestens 80 % vollständig sein.'),
            ]);
        }

        if ($profile->applications()->where('job_posting_id', $job->getKey())->exists()) {
            return back()->withErrors(['application' => __('Du hast dich bereits auf diese Stelle beworben.')]);
        }

        $job->load('screeningQuestions');
        $validated = $request->validate([
            'cover_letter' => ['nullable', 'string', 'max:10000'],
            'answers' => ['array'],
            'answers.*.question_id' => ['required', 'integer', 'distinct'],
            'answers.*.answer' => ['nullable', 'string', 'max:5000'],
        ]);
        /** @var list<array{question_id: int, answer?: string|null}> $answers */
        $answers = is_array($validated['answers'] ?? null) ? array_values($validated['answers']) : [];
        $answerMap = collect($answers)->keyBy('question_id');

        foreach ($job->screeningQuestions->where('is_required', true) as $question) {
            if (! filled($answerMap->get($question->getKey())['answer'] ?? null)) {
                return back()->withErrors(['answers' => __('Bitte beantworte alle Pflichtfragen.')]);
            }
        }

        $match = $matching->for($profile, $job);
        $application = DB::transaction(function () use ($profile, $job, $validated, $match, $request, $answers): JobApplication {
            $application = JobApplication::query()->create([
                'job_posting_id' => $job->getKey(),
                'candidate_profile_id' => $profile->getKey(),
                'status' => ApplicationStatus::New,
                'cover_letter' => $validated['cover_letter'] ?? null,
                'match_score' => $match['score'],
                'match_breakdown' => $match['factors'],
                'applied_at' => now(),
                'identity_revealed_at' => now(),
            ]);
            $application->statusHistory()->create([
                'changed_by' => $request->user()?->getKey(),
                'from_status' => null,
                'to_status' => ApplicationStatus::New->value,
                'note' => __('Bewerbung eingereicht'),
            ]);

            foreach ($answers as $answer) {
                if (! $job->screeningQuestions->contains('id', (int) $answer['question_id'])) {
                    continue;
                }

                $application->screeningAnswers()->create([
                    'job_screening_question_id' => $answer['question_id'],
                    'answer' => $answer['answer'] ?? null,
                ]);
            }

            return $application;
        });

        foreach ($job->company->users()->wherePivotNotNull('accepted_at')->get() as $member) {
            $member->notify(new ActivityNotification([
                'event' => 'application.created',
                'title' => __('Neue Bewerbung'),
                'message' => __('Für „:job“ ist eine neue Bewerbung eingegangen.', ['job' => $job->title]),
                'translations' => [
                    'de' => [
                        'title' => 'Neue Bewerbung',
                        'message' => sprintf('Für „%s“ ist eine neue Bewerbung eingegangen.', $job->title),
                    ],
                    'en' => [
                        'title' => 'New application',
                        'message' => sprintf('A new application was submitted for “%s”.', $job->title),
                    ],
                ],
                'url' => route('employer.pipeline', ['job' => $job->getKey()]),
                'application_id' => $application->getKey(),
            ]));
        }

        $audit->record('application.created', $application, after: [
            'job_posting_id' => $job->getKey(),
            'status' => ApplicationStatus::New->value,
            'match_score' => $match['score'],
        ], companyId: $job->company_id);
        $activity->record(
            'application.created',
            $request->user(),
            $job->company_id,
            $application,
            [
                'candidate_label' => $profile->anonymizedLabel(),
                'job_title' => $job->title,
            ],
            $request->user(),
            'shared',
        );
        Referral::query()
            ->where('referred_user_id', $request->user()?->getKey())
            ->where('status', ReferralStatus::Registered)
            ->update([
                'application_id' => $application->getKey(),
                'status' => ReferralStatus::Applied,
            ]);

        return redirect()->route('candidate.applications')->with('success', __('Deine Bewerbung wurde gesendet.'));
    }

    public function applications(Request $request): Response
    {
        $profile = $request->user()?->candidateProfile()->firstOrFail();

        return Inertia::render('candidate/Applications', [
            'applications' => $profile->applications()
                ->with([
                    'jobPosting.company:id,name,slug,logo_media_id',
                    'jobPosting.company.logoMedia:id,company_id,type,disk,path,original_name,mime_type,size_bytes,scan_result',
                    'statusHistory',
                    'interviews',
                    'visaCase.steps',
                ])
                ->latest('applied_at')
                ->get()
                ->map(function (JobApplication $application): array {
                    $data = $application->toArray();
                    $data['job_posting']['company'] = $this->serializeCompany(
                        $application->jobPosting->company,
                    );

                    return [
                        ...$data,
                        'pipeline_stage' => $application->pipelineStage(),
                    ];
                }),
            'invitations' => $profile->invitations()
                ->where('status', 'pending')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->with('jobPosting.company')
                ->latest()
                ->get(),
        ]);
    }

    public function companies(Request $request): Response
    {
        $profile = $request->user()?->candidateProfile()->firstOrFail();
        $companies = Company::query()
            ->where('status', 'active')
            ->whereHas('jobPostings', fn ($query) => $query
                ->where('status', JobStatus::Published)
                ->whereNotNull('published_at')
                ->when($profile->occupation_id, fn ($jobs) => $jobs->where('occupation_id', $profile->occupation_id)))
            ->withCount(['jobPostings as relevant_jobs_count' => fn ($query) => $query
                ->where('status', JobStatus::Published)
                ->whereNotNull('published_at')
                ->when($profile->occupation_id, fn ($jobs) => $jobs->where('occupation_id', $profile->occupation_id))])
            ->with([
                'logoMedia:id,company_id,type,disk,path,original_name,mime_type,size_bytes,scan_result',
                'trustMetric:id,company_id,response_rate,interview_attendance_rate,contract_compliance_rate,cases_count,is_top_company,calculated_at',
            ])
            ->orderByDesc('last_active_at')
            ->get([
                'id',
                'name',
                'slug',
                'industry',
                'city',
                'country_code',
                'employee_count',
                'description',
                'logo_media_id',
                'benefits',
                'last_active_at',
            ])
            ->map(fn (Company $company): array => $this->serializeCompany($company, includeTrustMetrics: true));

        return Inertia::render('candidate/Companies', ['companies' => $companies]);
    }

    public function respondToInvitation(
        Request $request,
        JobInvitation $invitation,
        CandidateMatchService $matching,
        AuditLogger $audit,
    ): RedirectResponse {
        $profile = $request->user()?->candidateProfile()
            ->with(['skills', 'languages', 'documents'])
            ->firstOrFail();
        abort_unless($invitation->candidate_profile_id === $profile->getKey(), 404);
        abort_unless($invitation->status === 'pending' && ($invitation->expires_at === null || $invitation->expires_at->isFuture()), 422);
        $validated = $request->validate(['response' => ['required', 'in:accepted,rejected']]);

        if ($validated['response'] === 'accepted' && ! $profile->canApply()) {
            return back()->withErrors(['profile' => __('Vervollständige dein Profil zu mindestens 80 %, um die Einladung anzunehmen.')]);
        }

        $invitation->update(['status' => $validated['response'], 'responded_at' => now()]);

        if ($validated['response'] === 'accepted') {
            /** @var JobPosting $job */
            $job = $invitation->jobPosting()->with(['skills', 'languages'])->firstOrFail();
            $match = $matching->for($profile, $job);
            $application = JobApplication::query()->firstOrCreate(
                ['job_posting_id' => $job->getKey(), 'candidate_profile_id' => $profile->getKey()],
                [
                    'status' => ApplicationStatus::New,
                    'match_score' => $match['score'],
                    'match_breakdown' => $match['factors'],
                    'identity_revealed_at' => now(),
                    'applied_at' => now(),
                ],
            );
            if ($application->wasRecentlyCreated) {
                $application->statusHistory()->create([
                    'changed_by' => $request->user()?->getKey(),
                    'from_status' => null,
                    'to_status' => ApplicationStatus::New->value,
                    'note' => __('Firmeneinladung angenommen'),
                ]);
            }
            $audit->record('invitation.accepted', $invitation, after: [
                'application_id' => $application->getKey(),
            ], companyId: $job->company_id);
        }

        return back()->with('success', __('Deine Antwort wurde gespeichert.'));
    }

    public function withdraw(
        Request $request,
        JobApplication $application,
        ApplicationWorkflow $workflow,
        AuditLogger $audit,
    ): RedirectResponse {
        $profile = $request->user()?->candidateProfile()->firstOrFail();
        abort_unless($application->candidate_profile_id === $profile->getKey(), 404);

        try {
            $workflow->assertCanTransition($application->status, ApplicationStatus::Withdrawn);
        } catch (DomainException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        $from = $application->status;
        $application->update(['status' => ApplicationStatus::Withdrawn, 'decided_at' => now()]);
        $application->statusHistory()->create([
            'changed_by' => $request->user()?->getKey(),
            'from_status' => $from->value,
            'to_status' => ApplicationStatus::Withdrawn->value,
            'note' => __('Vom Kandidaten zurückgezogen'),
        ]);
        $audit->record('application.withdrawn', $application, ['status' => $from->value], [
            'status' => ApplicationStatus::Withdrawn->value,
        ], companyId: $application->jobPosting->company_id);

        return back()->with('success', __('Die Bewerbung wurde zurückgezogen.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCompany(Company $company, bool $includeTrustMetrics = false): array
    {
        $serialized = [
            ...Arr::except($company->toArray(), ['logo_media', 'logo_path', 'trust_metric']),
            'logo_url' => $this->mediaUrl($company->logoMedia),
        ];

        if ($includeTrustMetrics) {
            $serialized['trust_metrics'] = $company->trustMetric?->publicPayload();
        }

        return $serialized;
    }

    private function mediaUrl(?CompanyMedia $media): ?string
    {
        if ($media?->scan_result !== 'clean') {
            return null;
        }

        return URL::temporarySignedRoute(
            'companies.media.download',
            now()->addMinutes(15),
            ['media' => $media],
        );
    }
}
