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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
            'timezone' => ['nullable', 'timezone:all'],
            'recurrence' => ['nullable', Rule::in(['daily', 'weekly', 'monthly'])],
            'recurrence_ends_at' => ['nullable', 'date', 'after:due_at'],
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

        $timezone = (string) ($validated['timezone'] ?? $user->timezone ?? 'UTC');
        $dueAt = Carbon::parse((string) $validated['due_at'], $timezone)
            ->setTimezone((string) config('app.timezone'));
        $recurrenceEndsAt = isset($validated['recurrence_ends_at'])
            ? Carbon::parse((string) $validated['recurrence_ends_at'], $timezone)
                ->setTimezone((string) config('app.timezone'))
            : null;
        $seriesUuid = isset($validated['recurrence']) ? (string) Str::uuid() : null;

        /** @var RecruiterReminder $reminder */
        $reminder = RecruiterReminder::query()->create([
            ...$validated,
            'due_at' => $dueAt,
            'timezone' => $timezone,
            'recurrence_ends_at' => $recurrenceEndsAt,
            'series_uuid' => $seriesUuid,
            'occurrence_key' => $seriesUuid === null ? null : $seriesUuid.':'.$dueAt->format('YmdHi'),
            'company_id' => $company->getKey(),
            'creator_id' => $user->getKey(),
            'assignee_id' => $assigneeId,
        ]);
        $reminder->events()->create([
            'actor_id' => $user->getKey(),
            'event' => 'created',
            'after' => $this->snapshot($reminder),
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
            'action' => ['nullable', Rule::in(['complete', 'reopen', 'snooze', 'discard'])],
            'completed' => ['nullable', 'boolean', 'required_without:action'],
            'snoozed_until' => ['nullable', 'required_if:action,snooze', 'date', 'after:now'],
        ]);

        $action = $validated['action'] ?? ($validated['completed'] ? 'complete' : 'reopen');
        $before = $this->snapshot($reminder);
        $changed = DB::transaction(function () use ($reminder, $action, $validated, $request, $before): bool {
            /** @var RecruiterReminder $locked */
            $locked = RecruiterReminder::query()->lockForUpdate()->findOrFail($reminder->getKey());

            if ($action === 'complete') {
                if ($locked->completed_at !== null) {
                    $this->createNextOccurrence($locked);

                    return false;
                }

                $locked->update(['completed_at' => now(), 'snoozed_until' => null]);
                $this->createNextOccurrence($locked);
            } elseif ($action === 'reopen') {
                if ($locked->completed_at === null && $locked->discarded_at === null) {
                    return false;
                }

                $locked->update([
                    'completed_at' => null,
                    'discarded_at' => null,
                    'notified_at' => null,
                ]);
            } elseif ($action === 'snooze') {
                $nextDue = Carbon::parse((string) $validated['snoozed_until'], $locked->timezone)
                    ->setTimezone((string) config('app.timezone'));
                if ($locked->due_at->equalTo($nextDue)) {
                    return false;
                }

                $locked->update([
                    'due_at' => $nextDue,
                    'snoozed_until' => $nextDue,
                    'notified_at' => null,
                ]);
            } else {
                if ($locked->discarded_at !== null) {
                    return false;
                }

                $locked->update(['discarded_at' => now()]);
            }

            $locked->events()->create([
                'actor_id' => $request->user()?->getKey(),
                'event' => $action,
                'before' => $before,
                'after' => $this->snapshot($locked->refresh()),
            ]);

            return true;
        }, 3);

        if ($changed) {
            $activity->record(
                'reminder.'.$action,
                $request->user(),
                $company,
                $reminder,
                ['title' => $reminder->title],
                idempotencyKey: "reminder:{$reminder->getKey()}:{$action}:{$reminder->updated_at?->timestamp}",
            );
        }

        return back()->with('success', match ($action) {
            'complete' => __('Die Erinnerung wurde erledigt.'),
            'reopen' => __('Die Erinnerung wurde wieder geöffnet.'),
            'snooze' => __('Die Erinnerung wurde verschoben.'),
            default => __('Die Erinnerung wurde verworfen.'),
        });
    }

    public function destroy(
        Request $request,
        RecruiterReminder $reminder,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $company = $currentCompany->forRequest($request);
        abort_unless($reminder->company_id === $company->getKey(), 404);
        Gate::authorize('delete', $reminder);
        $before = $this->snapshot($reminder);
        $reminder->update(['discarded_at' => now()]);
        $reminder->events()->create([
            'actor_id' => $request->user()?->getKey(),
            'event' => 'discard',
            'before' => $before,
            'after' => $this->snapshot($reminder->refresh()),
        ]);

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

    private function createNextOccurrence(RecruiterReminder $reminder): void
    {
        if ($reminder->recurrence === null || $reminder->series_uuid === null) {
            return;
        }

        $local = $reminder->due_at->clone()->setTimezone($reminder->timezone);
        $next = (match ($reminder->recurrence) {
            'daily' => $local->addDay(),
            'weekly' => $local->addWeek(),
            'monthly' => $local->addMonthNoOverflow(),
            default => $local,
        })->setTimezone((string) config('app.timezone'));
        if ($reminder->recurrence_ends_at !== null && $next->isAfter($reminder->recurrence_ends_at)) {
            return;
        }

        RecruiterReminder::query()->firstOrCreate(
            ['occurrence_key' => $reminder->series_uuid.':'.$next->format('YmdHi')],
            [
                ...$reminder->only([
                    'company_id', 'creator_id', 'assignee_id', 'candidate_profile_id',
                    'application_id', 'interview_id', 'job_posting_id', 'title', 'note',
                    'priority', 'timezone', 'recurrence', 'recurrence_ends_at', 'series_uuid',
                ]),
                'due_at' => $next,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function snapshot(RecruiterReminder $reminder): array
    {
        return collect($reminder->only([
            'assignee_id', 'priority', 'due_at', 'timezone', 'recurrence',
            'completed_at', 'discarded_at', 'snoozed_until',
        ]))->map(fn (mixed $value): mixed => $value instanceof Carbon
            ? $value->toIso8601String()
            : $value)->all();
    }
}
