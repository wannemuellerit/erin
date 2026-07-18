<?php

namespace App\Console\Commands;

use App\Services\Operations\SecurityBaselineAudit;
use Illuminate\Console\Command;

class SecurityAuditCommand extends Command
{
    protected $signature = 'erin:ops:security-audit
        {--json : Maschinenlesbares JSON ausgeben}';

    protected $description = 'Prüft die technische Sicherheitsbaseline der Produktionskonfiguration';

    public function handle(SecurityBaselineAudit $audit): int
    {
        $checks = $audit->checks();
        $failed = collect($checks)->where('status', 'fail')->count();
        $exitCode = $failed === 0 ? self::SUCCESS : self::FAILURE;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'schema_version' => 1,
                'status' => $exitCode === self::SUCCESS ? 'passed' : 'failed',
                'release_id' => config('operations.launch_evidence.release.id'),
                'commit_sha' => config('operations.launch_evidence.release.commit_sha'),
                'build_sha' => config('operations.build.sha'),
                'image_tag' => config('operations.build.image_tag'),
                'summary' => [
                    'passed' => collect($checks)->where('status', 'pass')->count(),
                    'failed' => $failed,
                ],
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exitCode;
        }

        $this->table(
            ['Prüfung', 'Status', 'Hinweis'],
            array_map(
                static fn (array $check): array => [
                    $check['id'],
                    mb_strtoupper($check['status']),
                    $check['message'],
                ],
                $checks,
            ),
        );
        $this->newLine();
        $this->line(
            sprintf(
                'Bestanden: %d, Fehler: %d',
                collect($checks)->where('status', 'pass')->count(),
                $failed,
            ),
        );

        return $exitCode;
    }
}
