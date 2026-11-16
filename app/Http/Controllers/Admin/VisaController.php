<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VisaCaseStatus;
use App\Models\CandidateDocument;
use App\Models\User;
use App\Models\VisaCase;
use App\Models\VisaCaseDocument;
use App\Models\VisaStep;
use App\Models\VisaTask;
use App\Services\Visa\VisaCaseWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VisaController extends AdminController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string'],
            'company_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
        ]);

        $cases = VisaCase::query()
            ->with([
                'company:id,name,slug',
                'candidateProfile:id,user_id,first_name,last_name,current_position',
                'candidateProfile.user:id,name,email',
                'application:id,job_posting_id,status',
                'application.jobPosting:id,title',
                'assignee:id,name,email',
                'steps.responsibleUser:id,name,email',
                'steps.tasks.assignee:id,name,email',
                'documents.document:id,candidate_profile_id,type,status,scan_result,expires_at,verified_at',
                'events' => fn ($query) => $query->latest()->limit(30),
            ])
            ->withCount([
                'steps',
                'steps as completed_steps_count' => fn (Builder $query): Builder => $query->where('status', 'completed'),
                'steps as overdue_steps_count' => fn (Builder $query): Builder => $query
                    ->whereDate('due_at', '<', today())
                    ->whereNotIn('status', ['completed', 'not_required']),
            ])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereHas('company', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('candidateProfile.user', function (Builder $query) use ($search): void {
                            $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when(
                isset($filters['status']) && VisaCaseStatus::tryFrom($filters['status']) !== null,
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->when(
                $filters['company_id'] ?? null,
                fn (Builder $query, int $companyId): Builder => $query->where('company_id', $companyId),
            )
            ->when(
                $filters['assignee_id'] ?? null,
                fn (Builder $query, int $assigneeId): Builder => $query->where('assigned_to', $assigneeId),
            )
            ->orderByRaw("case status when 'blocked' then 0 when 'active' then 1 else 2 end")
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/Visa', [
            'cases' => $cases,
            'filters' => $filters,
            'statuses' => array_map(
                static fn (VisaCaseStatus $status): string => $status->value,
                VisaCaseStatus::cases(),
            ),
            'platform_assignees' => User::query()
                ->whereIn('role', ['super_admin', 'support'])
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function update(Request $request, VisaCase $case, VisaCaseWorkflow $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['assign', 'block', 'resume', 'not_required', 'complete'])],
            'version' => ['required', 'integer', 'min:0'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'reason' => [
                Rule::requiredIf(fn (): bool => in_array($request->string('action')->toString(), [
                    'block', 'not_required', 'complete',
                ], true)),
                'nullable', 'string', 'min:5', 'max:3000',
            ],
        ]);
        if ($validated['action'] === 'assign') {
            $assignee = isset($validated['assigned_to'])
                ? User::query()->find((int) $validated['assigned_to'])
                : null;
            if ($assignee !== null && ! $assignee->isPlatformStaff()) {
                throw ValidationException::withMessages([
                    'assigned_to' => __('Visa-Fälle können nur Plattformmitarbeitenden zugewiesen werden.'),
                ]);
            }
        }

        $workflow->apply($case, $validated, $request);

        return back()->with('success', __('Der Visa-Fall wurde aktualisiert.'));
    }

    public function storeTask(Request $request, VisaCase $case, VisaStep $step): RedirectResponse
    {
        abort_unless($step->visa_case_id === $case->getKey(), 404);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'blocker' => ['nullable', 'string', 'max:3000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'visibility' => ['required', Rule::in(['platform', 'company', 'candidate', 'shared'])],
        ]);
        $this->assertResponsibleUser($case, $validated['assigned_to'] ?? null);

        $task = $step->tasks()->create([
            ...$validated,
            'created_by' => $request->user()?->getKey(),
        ]);
        $case->events()->create([
            'actor_id' => $request->user()?->getKey(),
            'event' => 'visa.task.created',
            'visibility' => $validated['visibility'],
            'payload' => ['task_id' => $task->getKey(), 'title' => $task->title],
        ]);
        $this->audit($request, 'visa.task_created', $task, [], $task->only([
            'visa_step_id', 'assigned_to', 'title', 'due_at', 'visibility',
        ]));

        return back()->with('success', __('Die Visa-Aufgabe wurde angelegt.'));
    }

    public function updateTask(Request $request, VisaTask $task): RedirectResponse
    {
        $task->loadMissing('step.visaCase');
        $case = $task->step->visaCase;
        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'blocked', 'completed'])],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'blocker' => ['nullable', 'string', 'max:3000'],
            'completion_evidence' => [
                Rule::requiredIf(fn (): bool => $request->string('status')->toString() === 'completed'),
                'nullable', 'string', 'min:3', 'max:5000',
            ],
            'visibility' => ['required', Rule::in(['platform', 'company', 'candidate', 'shared'])],
        ]);
        $this->assertResponsibleUser($case, $validated['assigned_to'] ?? null);
        $before = $task->only(array_keys($validated));
        $task->update([
            ...$validated,
            'completed_at' => $validated['status'] === 'completed' ? now() : null,
        ]);
        $case->events()->create([
            'actor_id' => $request->user()?->getKey(),
            'event' => 'visa.task.updated',
            'visibility' => $validated['visibility'],
            'payload' => ['task_id' => $task->getKey(), 'status' => $task->status],
        ]);
        $this->audit($request, 'visa.task_updated', $task, $before, $task->only(array_keys($validated)));

        return back()->with('success', __('Die Visa-Aufgabe wurde aktualisiert.'));
    }

    public function attachDocument(Request $request, VisaCase $case): RedirectResponse
    {
        $validated = $request->validate([
            'candidate_document_id' => ['required', 'integer', 'exists:candidate_documents,id'],
            'visa_step_id' => ['nullable', 'integer', 'exists:visa_steps,id'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'visibility' => ['required', Rule::in(['platform', 'company', 'candidate', 'shared'])],
            'translation_status' => ['required', Rule::in(['not_required', 'pending', 'in_progress', 'completed'])],
            'review_status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
        ]);
        $document = CandidateDocument::query()
            ->where('candidate_profile_id', $case->candidate_profile_id)
            ->find($validated['candidate_document_id']);
        abort_unless($document instanceof CandidateDocument, 404);
        if (isset($validated['visa_step_id'])) {
            abort_unless($case->steps()->whereKey($validated['visa_step_id'])->exists(), 404);
        }

        $assignment = VisaCaseDocument::query()->updateOrCreate(
            [
                'visa_case_id' => $case->getKey(),
                'candidate_document_id' => $document->getKey(),
            ],
            [
                ...$validated,
                'attached_by' => $request->user()?->getKey(),
            ],
        );
        $this->audit($request, 'visa.document_attached', $assignment, [], $assignment->only([
            'visa_case_id', 'visa_step_id', 'candidate_document_id', 'visibility',
            'translation_status', 'review_status',
        ]));

        return back()->with('success', __('Das Dokument wurde der Visa-Akte zugeordnet.'));
    }

    public function export(VisaCase $case): StreamedResponse
    {
        $case->load([
            'company:id,name',
            'candidateProfile:id,first_name,last_name,current_position',
            'application.jobPosting:id,title',
            'assignee:id,name,email',
            'steps.tasks.assignee:id,name,email',
            'documents.document:id,type,status,scan_result,expires_at',
            'events',
        ]);
        $summary = [
            'case' => $case->only([
                'id', 'status', 'progress', 'target_start_date', 'started_at',
                'completed_at', 'blocked_reason', 'closed_reason', 'credit_status',
            ]),
            'company' => $case->company->only(['id', 'name']),
            'candidate' => $case->candidateProfile->only([
                'id', 'first_name', 'last_name', 'current_position',
            ]),
            'job' => $case->application?->jobPosting?->only(['id', 'title']),
            'assignee' => $case->assignee?->only(['id', 'name', 'email']),
            'steps' => $case->steps->map(fn (VisaStep $step): array => [
                ...$step->only([
                    'id', 'key', 'title', 'status', 'due_at', 'notes', 'blocker',
                    'completion_evidence', 'visibility',
                ]),
                'tasks' => $step->tasks->map->only([
                    'id', 'title', 'status', 'due_at', 'notes', 'blocker',
                    'completion_evidence', 'visibility',
                ]),
            ]),
            'documents' => $case->documents->map(fn (VisaCaseDocument $assignment): array => [
                ...$assignment->only([
                    'id', 'visa_step_id', 'purpose', 'visibility',
                    'translation_status', 'review_status',
                ]),
                'document' => $assignment->document?->only([
                    'id', 'type', 'status', 'scan_result', 'expires_at',
                ]),
            ]),
            'timeline' => $case->events->map->only([
                'event', 'visibility', 'payload', 'created_at',
            ]),
        ];

        return response()->streamDownload(
            static function () use ($summary): void {
                echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            },
            'visa-case-'.$case->getKey().'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    private function assertResponsibleUser(VisaCase $case, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }
        $valid = User::query()->whereKey($userId)->where(function (Builder $query) use ($case): void {
            $query->whereIn('role', ['super_admin', 'support'])
                ->orWhereHas('companyMemberships', fn (Builder $membership): Builder => $membership
                    ->where('company_id', $case->company_id)
                    ->whereNotNull('accepted_at'));
        })->exists();
        if (! $valid) {
            throw ValidationException::withMessages([
                'assigned_to' => __('Verantwortliche müssen zur Firma oder zum Plattformteam gehören.'),
            ]);
        }
    }
}
