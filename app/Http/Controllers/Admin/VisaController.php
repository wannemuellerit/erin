<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VisaCaseStatus;
use App\Models\VisaCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
        ]);
    }
}
