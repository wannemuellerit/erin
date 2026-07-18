<?php

namespace App\Console\Commands;

use App\Services\Billing\StripeStagingReadiness;
use Illuminate\Console\Command;

class CheckStripeStagingReadinessCommand extends Command
{
    protected $signature = 'erin:stripe:staging-check
        {--remote : Konfigurierte Prices mit ausschließlich lesenden Stripe-API-Aufrufen prüfen}';

    protected $description = 'Prüft die Stripe-Testmodus-Konfiguration, ohne Stripe- oder Datenbankwerte zu verändern.';

    public function handle(StripeStagingReadiness $readiness): int
    {
        $remote = (bool) $this->option('remote');
        $this->components->info(
            'Sicherer Stripe-Staging-Check: Es werden keine Stripe- oder Datenbankwerte verändert.',
        );

        $report = $readiness->inspect($remote);
        $this->table(
            ['Prüfung', 'Status', 'Ergebnis'],
            collect($report['checks'])
                ->map(fn (array $check): array => [
                    $check['label'],
                    match ($check['status']) {
                        'passed' => 'Bestanden',
                        'warning' => 'Hinweis',
                        'failed' => 'Fehlgeschlagen',
                        default => 'Unbekannt',
                    },
                    $check['message'],
                ])
                ->all(),
        );

        if ($report['ready']) {
            $this->components->info($remote
                ? 'Die lokale Konfiguration, Stripe-Test-Prices und der Webhook-Endpunkt sind bereit.'
                : 'Die lokale Staging-Konfiguration ist bereit; die optionale Remote-Prüfung steht noch aus.');

            return self::SUCCESS;
        }

        $this->components->error(
            'Die Stripe-Staging-Abnahme ist noch nicht bereit. Es wurden keine Änderungen vorgenommen.',
        );

        return self::FAILURE;
    }
}
