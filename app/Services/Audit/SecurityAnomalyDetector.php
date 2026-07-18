<?php

namespace App\Services\Audit;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\SecurityAlert;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SecurityAnomalyDetector
{
    /**
     * @return Collection<int, SecurityAlert>
     */
    public function detect(): Collection
    {
        $logs = AuditLog::query()
            ->where('created_at', '>=', now()->subHour())
            ->whereNotNull('actor_id')
            ->get();
        $alerts = collect();

        foreach ($logs->groupBy('actor_id') as $actorId => $actorLogs) {
            $denied = $actorLogs->filter(fn (AuditLog $log): bool => (int) data_get(
                $log->metadata,
                'response_status',
                200,
            ) >= 400 && Carbon::parse($log->created_at)->gte(now()->subMinutes(10)));
            if ($denied->count() >= 5) {
                $alerts->push($this->raise(
                    'denied_request_burst',
                    (int) $actorId,
                    'warning',
                    ['requests' => $denied->count(), 'window_minutes' => 10],
                ));
            }

            $downloads = $actorLogs->filter(fn (AuditLog $log): bool => data_get(
                $log->metadata,
                'route',
            ) === 'documents.download' && Carbon::parse($log->created_at)->gte(now()->subMinutes(10)));
            if ($downloads->count() >= 20) {
                $alerts->push($this->raise(
                    'document_download_burst',
                    (int) $actorId,
                    'critical',
                    ['downloads' => $downloads->count(), 'window_minutes' => 10],
                ));
            }

            $ipCount = $actorLogs->pluck('ip_address')->filter()->unique()->count();
            if ($ipCount >= 3) {
                $alerts->push($this->raise(
                    'multiple_ip_activity',
                    (int) $actorId,
                    'warning',
                    ['distinct_ips' => $ipCount, 'window_minutes' => 60],
                ));
            }
        }

        return $alerts->filter();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function raise(
        string $type,
        int $userId,
        string $severity,
        array $details,
    ): SecurityAlert {
        $fingerprint = hash('sha256', "{$type}:{$userId}:".now()->toDateString());
        $alert = SecurityAlert::query()->firstOrNew(['fingerprint' => $fingerprint]);
        $notify = ! $alert->exists || $alert->status === 'resolved';
        $alert->fill([
            'type' => $type,
            'severity' => $severity,
            'status' => 'open',
            'user_id' => $userId,
            'details' => $details,
            'occurrences' => $alert->exists ? $alert->occurrences + 1 : 1,
            'first_detected_at' => $alert->first_detected_at ?? now(),
            'last_detected_at' => now(),
            'resolved_by' => null,
            'resolved_at' => null,
        ])->save();

        if ($notify) {
            User::query()
                ->where('role', UserRole::SuperAdmin)
                ->where('status', UserStatus::Active)
                ->each(fn (User $admin) => $admin->notify(new ActivityNotification([
                    'event' => 'security.anomaly_detected',
                    'title_de' => 'Sicherheitsauffälligkeit erkannt',
                    'title_en' => 'Security anomaly detected',
                    'message_de' => "Erin hat das Muster „{$type}“ erkannt.",
                    'message_en' => "Erin detected the pattern \"{$type}\".",
                    'url' => route('admin.audit.index'),
                ])));
        }

        return $alert;
    }
}
