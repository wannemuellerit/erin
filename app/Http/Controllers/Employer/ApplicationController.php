<?php

namespace App\Http\Controllers\Employer;

use App\Enums\ApplicationStatus;
use App\Enums\ReferralStatus;
use App\Enums\VisaCaseStatus;
use App\Enums\VisaStepStatus;
use App\Http\Controllers\Controller;
use App\Models\CandidateInternalReview;
use App\Models\JobApplication;
use App\Models\Referral;
use App\Notifications\ActivityNotification;
use App\Services\Activity\ActivityRecorder;
use App\Services\Applications\ApplicationWorkflow;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\EntitlementService;
use App\Services\Companies\CurrentCompany;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationController extends Controller
{
    public function pipeline(Request $request, CurrentCompany $currentCompany): Response
    {
        $company = $currentCompany->forRequest($request);
        $applications = JobApplication::query()
            ->whereHas('jobPosting', fn ($query) => $query->where('company_id', $company->getKey()))
            ->when($request->integer('job'), fn ($query) => $query->where('job_posting_id', $request->integer('job')))
            ->with([
                'jobPosting:id,title',
                'candidateProfile:id,user_id,first_name,last_name,current_country_code,current_position,desired_position,experience_years',
                'candidateProfile.user:id,name,email',
            ])
            ->latest('applied_at')
            ->get()
            ->map(fn (JobApplication $application): array => [
                'id' => $application->getKey(),
                'status' => $application->status->value,
                'pipeline_stage' => $application->pipelineStage(),
                'match_score' => $application->match_score,
                'applied_at' => $application->applied_at->toIso8601String(),
                'job' => $application->jobPosting?->only(['id', 'title']),
                'candidate' => [
                    'id' => $application->candidate_profile_id,
                    'name' => $application->identityIsRevealed()
                        ? trim($application->candidateProfile->first_name.' '.$application->candidateProfile->last_name)
                        : $application->candidateProfile->anonymizedLabel(),
                    'country' => $application->candidateProfile->current_country_code,
                    'position' => $application->candidateProfile->desired_position
                        ?: $application->candidateProfile->current_position,
                    'experience_years' => $application->candidateProfile->experience_years,
                    'identity_revealed' => $application->identityIsRevealed(),
                ],
            ])
            ->groupBy('pipeline_stage');

        return Inertia::render('employer/Pipeline', [
            'pipeline' => $applications,
            'jobs' => $company->jobPostings()->select(['id', 'title', 'status'])->latest()->get(),
            'statuses' => collect(ApplicationStatus::cases())->map(fn ($status) => [
                'value' => $status->value,
                'pipeline_stage' => $status->pipelineStage(),
            ]),
            'selected_job' => $request->integer('job') ?: null,
        ]);
    }

    public function updateStatus(
        Request $request,
        JobApplication $application,
        CurrentCompany $currentCompany,
        ApplicationWorkflow $workflow,
        EntitlementService $entitlements,
        AuditLogger $audit,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($application->jobPosting()->where('company_id', $company->getKey())->exists(), 404);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        $validated = $request->validate([
            'status' => ['required', Rule::enum(ApplicationStatus::class)],
            'note' => ['nullable', 'string', 'max:3000'],
        ]);
        $target = ApplicationStatus::from($validated['status']);
        $originalStatus = $application->status;

        try {
            DB::transaction(function () use (
                $application,
                $target,
                $validated,
                $request,
                $company,
                $entitlements,
                $workflow,
                &$originalStatus,
            ): void {
                $lockedApplication = JobApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $from = $lockedApplication->status;
                $originalStatus = $from;
                $workflow->assertCanTransition($from, $target);

                if ($target === ApplicationStatus::VisaInProgress && ! $lockedApplication->visaCase()->exists()) {
                    $visaCase = $lockedApplication->visaCase()->create([
                        'company_id' => $company->getKey(),
                        'candidate_profile_id' => $lockedApplication->candidate_profile_id,
                        'status' => VisaCaseStatus::Active,
                        'started_at' => now(),
                        'progress' => 0,
                    ]);
                    $entitlements->consumeVisaCredit($company, $visaCase->getKey());

                    foreach ($this->visaSteps() as $index => $step) {
                        $visaCase->steps()->create([
                            'key' => $step['key'],
                            'title' => $step['title'],
                            'status' => $index === 0 ? VisaStepStatus::InProgress : VisaStepStatus::Open,
                        ]);
                    }
                }

                $lockedApplication->update([
                    'status' => $target,
                    'identity_revealed_at' => $lockedApplication->identity_revealed_at ?? now(),
                    'decided_at' => in_array($target, [
                        ApplicationStatus::Accepted,
                        ApplicationStatus::Rejected,
                        ApplicationStatus::Hired,
                    ], true) ? now() : $lockedApplication->decided_at,
                ]);
                $lockedApplication->statusHistory()->create([
                    'changed_by' => $request->user()?->getKey(),
                    'from_status' => $from->value,
                    'to_status' => $target->value,
                    'note' => $validated['note'] ?? null,
                ]);

                if ($target === ApplicationStatus::Hired) {
                    Referral::query()
                        ->where('referred_user_id', $lockedApplication->candidateProfile->user_id)
                        ->whereIn('status', [ReferralStatus::Applied, ReferralStatus::Registered])
                        ->get()
                        ->each(fn (Referral $referral): bool => $referral->update([
                            'application_id' => $lockedApplication->getKey(),
                            'status' => ReferralStatus::Holding,
                            'hired_at' => now(),
                            'hold_until' => now()->addDays(30),
                        ]));
                }
            });
        } catch (DomainException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        $application->refresh();
        $application->candidateProfile->user->notify(new ActivityNotification([
            'event' => 'application.status_changed',
            'title' => __('Status deiner Bewerbung aktualisiert'),
            'message' => __('Deine Bewerbung für „:job“ ist jetzt „:status“.', [
                'job' => $application->jobPosting->title,
                'status' => $target->value,
            ]),
            'translations' => [
                'de' => [
                    'title' => 'Status deiner Bewerbung aktualisiert',
                    'message' => sprintf(
                        'Deine Bewerbung für „%s“ ist jetzt „%s“.',
                        $application->jobPosting->title,
                        $target->value,
                    ),
                ],
                'en' => [
                    'title' => 'Your application status was updated',
                    'message' => sprintf(
                        'Your application for “%s” is now “%s”.',
                        $application->jobPosting->title,
                        $target->value,
                    ),
                ],
            ],
            'url' => route('candidate.applications'),
            'application_id' => $application->getKey(),
        ]));

        $audit->record(
            'application.status_changed',
            $application,
            ['status' => $originalStatus->value],
            ['status' => $target->value],
            ['human_decision' => true],
            companyId: $company->getKey(),
        );
        $activity->record(
            'application.status_changed',
            $request->user(),
            $company,
            $application,
            [
                'candidate_label' => $application->candidateProfile->anonymizedLabel(),
                'job_title' => $application->jobPosting->title,
                'status' => $target->value,
            ],
            $application->candidateProfile->user,
            'shared',
        );

        return back()->with('success', __('Der Bewerbungsstatus wurde aktualisiert.'));
    }

    public function reviewCandidate(
        Request $request,
        JobApplication $application,
        CurrentCompany $currentCompany,
        AuditLogger $audit,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($application->jobPosting()->where('company_id', $company->getKey())->exists(), 404);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        $user = $request->user();
        abort_if($user === null, 401);

        $validated = $request->validate([
            'metrics' => [
                'required',
                'array:punctual,friendly,good_communication,documents_complete,honest_information',
            ],
            'metrics.punctual' => ['required', 'boolean'],
            'metrics.friendly' => ['required', 'boolean'],
            'metrics.good_communication' => ['required', 'boolean'],
            'metrics.documents_complete' => ['required', 'boolean'],
            'metrics.honest_information' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $existing = CandidateInternalReview::query()
            ->where('company_id', $company->getKey())
            ->where('application_id', $application->getKey())
            ->where('reviewer_id', $user->getKey())
            ->first();
        $before = $existing?->only(['metrics', 'notes']) ?? [];
        /** @var array<string, bool> $metrics */
        $metrics = $validated['metrics'];
        $review = CandidateInternalReview::query()->updateOrCreate(
            [
                'company_id' => $company->getKey(),
                'application_id' => $application->getKey(),
                'reviewer_id' => $user->getKey(),
            ],
            [
                'candidate_profile_id' => $application->candidate_profile_id,
                'metrics' => $metrics,
                'notes' => $validated['notes'] ?? null,
            ],
        );

        $audit->record(
            $existing === null ? 'candidate.internal_review_created' : 'candidate.internal_review_updated',
            $review,
            $before,
            $review->only(['metrics', 'notes']),
            [
                'application_id' => $application->getKey(),
                'candidate_profile_id' => $application->candidate_profile_id,
                'internal_only' => true,
            ],
            companyId: $company->getKey(),
        );

        return back()->with('success', __('Die interne Kandidatenbewertung wurde gespeichert.'));
    }

    /**
     * @return list<array{key: string, title: string}>
     */
    private function visaSteps(): array
    {
        return [
            ['key' => 'registration', 'title' => __('Registrierung')],
            ['key' => 'documents', 'title' => __('Dokumente hochladen')],
            ['key' => 'review', 'title' => __('Prüfung')],
            ['key' => 'employer', 'title' => __('Arbeitgeber gefunden')],
            ['key' => 'contract', 'title' => __('Vertrag')],
            ['key' => 'recognition', 'title' => __('Anerkennung')],
            ['key' => 'visa', 'title' => __('Visum')],
            ['key' => 'flight', 'title' => __('Flug')],
            ['key' => 'local_registration', 'title' => __('Anmeldung')],
            ['key' => 'work_start', 'title' => __('Arbeitsbeginn')],
        ];
    }
}
