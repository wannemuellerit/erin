<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Platform\PlatformSettings;
use Illuminate\Console\Command;

class PruneAuditLogsCommand extends Command
{
    protected $signature = 'erin:audit:prune {--execute} {--json}';

    protected $description = 'Löscht alte, nicht gesperrte Audit-Protokolle nach konfigurierter Frist';

    public function handle(PlatformSettings $settings): int
    {
        $days = max(0, (int) $settings->get(
            'retention.audit_log_days',
            PlatformSettings::DEFAULT_RETENTION['audit_log_days'],
        ));
        $query = AuditLog::query()
            ->whereNull('retention_locked_at')
            ->where('created_at', '<', now()->subDays($days));
        $affected = $days > 0
            ? ((bool) $this->option('execute') ? $query->delete() : $query->count())
            : 0;
        $this->line((string) json_encode([
            'mode' => $this->option('execute') ? 'execute' : 'dry_run',
            'days' => $days,
            'affected' => $affected,
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
