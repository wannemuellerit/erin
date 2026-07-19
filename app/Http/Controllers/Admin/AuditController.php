<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\SecurityAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $logs = $this->filteredQuery($filters)
            ->with([
                'actor:id,name,email,role',
                'company:id,name,slug',
            ])
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
            'security_alerts' => SecurityAlert::query()
                ->with('user:id,name,email')
                ->where('status', 'open')
                ->latest('last_detected_at')
                ->limit(20)
                ->get(),
            'can_manage_audit' => $request->user()?->role === UserRole::SuperAdmin,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'event' => ['nullable', 'string', 'max:120'],
            'actor_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $this->audit($request, 'admin.audit.exported', metadata: ['filters' => $filters]);

        return response()->streamDownload(function () use ($filters): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            fputcsv($handle, ['Zeit', 'Ereignis', 'Akteur-ID', 'Firma-ID', 'Zieltyp', 'Ziel-ID', 'IP-Adresse']);
            $this->filteredQuery($filters)->latest('created_at')->chunkById(
                500,
                /** @param Collection<int, AuditLog> $logs */
                function ($logs) use ($handle): void {
                    foreach ($logs as $log) {
                        fputcsv($handle, [
                            $log->created_at->toIso8601String(),
                            $this->csvSafe($log->event),
                            $log->actor_id,
                            $log->company_id,
                            $this->csvSafe($log->auditable_type),
                            $log->auditable_id,
                            $this->csvSafe($log->ip_address),
                        ]);
                    }
                },
                'id',
            );
            fclose($handle);
        }, 'erin-audit-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function resolve(Request $request, SecurityAlert $alert): RedirectResponse
    {
        $alert->update([
            'status' => 'resolved',
            'resolved_by' => $request->user()?->getKey(),
            'resolved_at' => now(),
        ]);
        $this->audit($request, 'admin.security_alert.resolved', $alert);

        return back()->with('success', __('Der Sicherheitsalarm wurde als geprüft markiert.'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    private function filteredQuery(array $filters): Builder
    {
        return AuditLog::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('event', 'like', "%{$search}%")
                        ->orWhereHas('actor', fn (Builder $query): Builder => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"));
                });
            })
            ->when($filters['event'] ?? null, fn (Builder $query, string $event): Builder => $query->where('event', $event))
            ->when($filters['actor_id'] ?? null, fn (Builder $query, int $id): Builder => $query->where('actor_id', $id))
            ->when($filters['company_id'] ?? null, fn (Builder $query, int $id): Builder => $query->where('company_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
            ->when($filters['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
    }

    private function csvSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
