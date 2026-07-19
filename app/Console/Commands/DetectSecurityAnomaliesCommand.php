<?php

namespace App\Console\Commands;

use App\Services\Audit\SecurityAnomalyDetector;
use Illuminate\Console\Command;

class DetectSecurityAnomaliesCommand extends Command
{
    protected $signature = 'erin:audit:detect-anomalies {--json}';

    protected $description = 'Erkennt verdächtige Zugriffsmuster in den Audit-Protokollen';

    public function handle(SecurityAnomalyDetector $detector): int
    {
        $alerts = $detector->detect();
        $this->line((string) json_encode([
            'detected' => $alerts->count(),
            'alert_ids' => $alerts->pluck('id'),
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
