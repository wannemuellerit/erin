<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Models\CandidateProfile;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\RecruiterReminder;
use App\Services\Activity\ActivityRecorder;
use App\Services\Companies\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReminderController extends Controller
{
    public function store(
        Request $request,
        CurrentCompany $currentCompany,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($currentCompany->membership($request)->role->canRecruit(), 403);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'note' => ['nullable', 'string', 'max:3000'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high'])],
            'due_at' => ['required', 'date', 'after:now'],
            'assignee_id' => ['nullable', 'integer'],
            'candidate_profile_id' => ['nullable', 'integer'],
            'application_id' => ['nullable', 'integer'],
            'interview_id' => ['nullable', 'integer'],
            'job_posting_id' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        abort_if($user === null, 401);
        $assigneeId = (int) ($validated['assignee_id'] ?? $user->getKey());

        $assigneeMembership = $company->memberships()
            ->where('user_id', $assigneeId)
            ->whereNotNull('accepted_at')
            ->first();
        abort_unless(
            $assigneeMembership?->role->canRecruit() === true,
            422,
            __('Erinnerungen können nur aktiven Recruitern zugewiesen werden.'),
        );

        $this->assertTargetsBelongToCompany($company->getKey(), $validated);

        /** @var RecruiterReminder $reminder */
        $reminder = RecruiterReminder::query()->create([
            ...$validated,
            'company_id' => $company->getKey(),
            'creator_id' => $user->getKey(),
            'assignee_id' => $assigneeId,
        ]);

        $activity->record(
            'reminder.created',
            $user,
            $company,
            $reminder,
            ['title' => $reminder->title],
        );

        return back()->with('success', __('Die Erinnerung wurde angelegt.'));
    }

    public function update(
        Request $request,
        RecruiterReminder $reminder,
        CurrentCompany $currentCompany,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($reminder->company_id === $company->getKey(), 404);
        Gate::authorize('update', $reminder);
        $validated = $request->validate([
            'completed' => ['required', 'boolean'],
        ]);

        $reminder->update([
            'completed_at' => $validated['completed'] ? now() : null,
            'notified_at' => $validated['completed'] ? $reminder->notified_at : null,
        ]);

        $activity->record(
            $validated['completed'] ? 'reminder.completed' : 'reminder.reopened',
            $request->user(),
            $company,
            $reminder,
            ['title' => $reminder->title],
        );

        return back()->with('success', $validated['completed']
            ? __('Die Erinnerung wurde erledigt.')
            : __('Die Erinnerung wurde wieder geöffnet.'));
    }

    public function destroy(
        Request $request,
        RecruiterReminder $reminder,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($reminder->company_id === $company->getKey(), 404);
        Gate::authorize('delete', $reminder);
        $reminder->delete();

        return back()->with('success', __('Die Erinnerung wurde gelöscht.'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertTargetsBelongToCompany(int $companyId, array $data): void
    {
        if (isset($data['job_posting_id'])) {
            JobPosting::query()->where('company_id', $companyId)->findOrFail($data['job_posting_id']);
        }

        if (isset($data['application_id'])) {
            JobApplication::query()
                ->whereHas('jobPosting', fn ($query) => $query->where('company_id', $companyId))
                ->findOrFail($data['application_id']);
        }

        if (isset($data['interview_id'])) {
            Interview::query()
                ->whereHas(
                    'application.jobPosting',
                    fn ($query) => $query->where('company_id', $companyId),
                )
                ->findOrFail($data['interview_id']);
        }

        if (isset($data['candidate_profile_id'])) {
            CandidateProfile::query()
                ->where(function ($query) use ($companyId): void {
                    $query->published()
                        ->orWhereHas(
                            'applications.jobPosting',
                            fn ($query) => $query->where('company_id', $companyId),
                        );
                })
                ->findOrFail($data['candidate_profile_id']);
        }
    }
}
