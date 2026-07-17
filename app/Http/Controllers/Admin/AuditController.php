<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends AdminController
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'event' => ['nullable', 'string', 'max:120'],
            'actor_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $logs = AuditLog::query()
            ->with([
                'actor:id,name,email,role',
                'company:id,name,slug',
            ])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('event', 'like', "%{$search}%")
                        ->orWhereHas('actor', function (Builder $query) use ($search): void {
                            $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when(
                $filters['event'] ?? null,
                fn (Builder $query, string $event): Builder => $query->where('event', $event),
            )
            ->when(
                $filters['actor_id'] ?? null,
                fn (Builder $query, int $actorId): Builder => $query->where('actor_id', $actorId),
            )
            ->when(
                $filters['company_id'] ?? null,
                fn (Builder $query, int $companyId): Builder => $query->where('company_id', $companyId),
            )
            ->when(
                $filters['from'] ?? null,
                fn (Builder $query, string $from): Builder => $query->whereDate('created_at', '>=', $from),
            )
            ->when(
                $filters['until'] ?? null,
                fn (Builder $query, string $until): Builder => $query->whereDate('created_at', '<=', $until),
            )
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('admin/Audit', [
            'logs' => $logs,
            'filters' => $filters,
            'events' => AuditLog::query()
                ->select('event')
                ->distinct()
                ->orderBy('event')
                ->pluck('event'),
        ]);
    }
}
