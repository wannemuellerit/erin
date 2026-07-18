<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Models\Feedback;
use App\Models\ModerationCase;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Trust\CompanyTrustMetricService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ModerationController extends AdminController
{
    public function reviewFeedback(
        Request $request,
        Feedback $feedback,
        CompanyTrustMetricService $trustMetrics,
    ): RedirectResponse {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $feedback = DB::transaction(function () use ($feedback, $validated, $request): Feedback {
            /** @var Feedback $locked */
            $locked = Feedback::query()->lockForUpdate()->findOrFail($feedback->getKey());
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'decision' => __('Dieses Feedback wurde bereits geprüft.'),
                ]);
            }
            $before = $locked->only(['status', 'reviewed_at', 'reviewed_by']);
            $locked->update([
                'status' => $validated['decision'],
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()?->getKey(),
            ]);

            $this->audit(
                $request,
                'admin.feedback.reviewed',
                $locked,
                $before,
                $locked->only(['status', 'reviewed_at', 'reviewed_by']),
                ['reason' => $validated['reason']],
            );

            return $locked;
        }, 3);

        if ($feedback->subjectCompany !== null) {
            $trustMetrics->recalculate($feedback->subjectCompany);
        }

        $feedback->author->notify(new ActivityNotification([
            'event' => 'feedback.'.$validated['decision'],
            'translations' => [
                'de' => [
                    'title' => 'Feedback wurde geprüft',
                    'message' => $validated['decision'] === 'approved'
                        ? 'Dein Feedback wurde freigegeben.'
                        : 'Dein Feedback wurde nach der Prüfung abgelehnt.',
                ],
                'en' => [
                    'title' => 'Feedback reviewed',
                    'message' => $validated['decision'] === 'approved'
                        ? 'Your feedback has been approved.'
                        : 'Your feedback was rejected after review.',
                ],
            ],
            'url' => route('support.index'),
            'feedback_id' => $feedback->getKey(),
        ]));

        return back()->with('success', __('Das Feedback wurde geprüft.'));
    }

    public function updateCase(Request $request, ModerationCase $case): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['assign', 'escalate', 'resolve', 'dismiss', 'block'])],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['nullable', Rule::in(['normal', 'high', 'urgent'])],
            'resolution' => [
                Rule::requiredIf(fn (): bool => in_array($request->string('action')->toString(), [
                    'resolve',
                    'dismiss',
                    'block',
                ], true)),
                'nullable',
                'string',
                'min:5',
                'max:3000',
            ],
        ]);

        /** @var User|null $assignee */
        $assignee = isset($validated['assigned_to'])
            ? User::query()->whereKey($validated['assigned_to'])->firstOrFail()
            : null;
        if ($assignee !== null && ! $assignee->isPlatformStaff()) {
            throw ValidationException::withMessages([
                'assigned_to' => __('Moderationsfälle können nur Plattformmitarbeitenden zugewiesen werden.'),
            ]);
        }
        $case = DB::transaction(function () use ($case, $validated, $request, $assignee): ModerationCase {
            /** @var ModerationCase $locked */
            $locked = ModerationCase::query()
                ->with(['subjectUser', 'subjectCompany'])
                ->lockForUpdate()
                ->findOrFail($case->getKey());
            if (in_array($locked->status, ['resolved', 'dismissed'], true)) {
                throw ValidationException::withMessages([
                    'action' => __('Dieser Moderationsfall ist bereits abgeschlossen.'),
                ]);
            }
            $before = $locked->only([
                'status',
                'priority',
                'assigned_to',
                'resolution',
                'resolved_at',
                'escalated_at',
            ]);
            /** @var 'assign'|'escalate'|'resolve'|'dismiss'|'block' $action */
            $action = $validated['action'];
            $locked->update([
                'status' => match ($action) {
                    'assign' => 'in_review',
                    'escalate' => 'escalated',
                    'resolve', 'block' => 'resolved',
                    'dismiss' => 'dismissed',
                },
                'priority' => $validated['priority'] ?? $locked->priority,
                'assigned_to' => $assignee?->getKey() ?? $locked->assigned_to,
                'resolution' => $validated['resolution'] ?? $locked->resolution,
                'escalated_at' => $action === 'escalate' ? now() : $locked->escalated_at,
                'resolved_at' => in_array($action, ['resolve', 'dismiss', 'block'], true)
                    ? now()
                    : null,
            ]);

            if ($action === 'block') {
                $locked->subjectUser?->update(['status' => UserStatus::Blocked]);
                $locked->subjectCompany?->update(['status' => CompanyStatus::Blocked]);

                $blockedUserIds = collect([$locked->subject_user_id])
                    ->when(
                        $locked->subject_company_id !== null,
                        fn ($ids) => $ids->merge(
                            $locked->subjectCompany?->users()->pluck('users.id') ?? collect(),
                        ),
                    )
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                if ($blockedUserIds !== []) {
                    DB::table('sessions')->whereIn('user_id', $blockedUserIds)->delete();
                }
            }

            $this->audit(
                $request,
                'admin.moderation_case.'.$action,
                $locked,
                $before,
                $locked->only([
                    'status',
                    'priority',
                    'assigned_to',
                    'resolution',
                    'resolved_at',
                    'escalated_at',
                ]),
            );

            return $locked;
        }, 3);

        if ($validated['action'] === 'block' && $case->subjectUser !== null) {
            $case->subjectUser->notify(new ActivityNotification([
                'event' => 'account.blocked',
                'translations' => [
                    'de' => [
                        'title' => 'Konto gesperrt',
                        'message' => 'Dein Konto wurde nach einer Moderationsprüfung gesperrt. Bitte kontaktiere den Support.',
                    ],
                    'en' => [
                        'title' => 'Account blocked',
                        'message' => 'Your account was blocked after a moderation review. Please contact support.',
                    ],
                ],
                'url' => route('support.index'),
            ]));
        }

        return back()->with('success', __('Der Moderationsfall wurde aktualisiert.'));
    }
}
