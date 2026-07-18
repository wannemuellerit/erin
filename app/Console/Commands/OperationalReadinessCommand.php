<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\Documents\ClamAvScanner;
use App\Services\Ticketing\ZammadEndpoint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class OperationalReadinessCommand extends Command
{
    protected $signature = 'erin:ops:readiness
        {--probe : Datenbank, privaten Speicher und ClamAV aktiv prüfen}
        {--strict : Auch Warnungen als fehlgeschlagene Freigabe behandeln}
        {--json : Maschinenlesbares JSON ausgeben}';

    protected $description = 'Prüft Produktionskonfiguration und dokumentierte Launch-Gates';

    public function handle(ClamAvScanner $scanner): int
    {
        $checks = $this->configurationChecks();

        if ((bool) $this->option('probe')) {
            $checks = [...$checks, ...$this->activeChecks($scanner)];
        }

        $failed = collect($checks)->where('status', 'fail')->count();
        $warnings = collect($checks)->where('status', 'warn')->count();
        $exitCode = $failed > 0 || ((bool) $this->option('strict') && $warnings > 0)
            ? self::FAILURE
            : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'status' => match (true) {
                    $exitCode !== self::SUCCESS => 'not_ready',
                    $warnings > 0 => 'ready_with_warnings',
                    default => 'ready',
                },
                'strict' => (bool) $this->option('strict'),
                'active_probe' => (bool) $this->option('probe'),
                'summary' => [
                    'passed' => collect($checks)->where('status', 'pass')->count(),
                    'warnings' => $warnings,
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
        $this->line("Bestanden: {$this->count($checks, 'pass')}, Warnungen: {$warnings}, Fehler: {$failed}");

        return $exitCode;
    }

    /**
     * @return list<array{id: string, status: 'pass'|'warn'|'fail', message: string}>
     */
    private function configurationChecks(): array
    {
        $production = config('app.env') === 'production';
        $key = config('app.key');
        $privateDisk = config('filesystems.disks.private');
        $retention = config('operations.retention', []);
        $gates = config('operations.gates', []);

        return [
            $this->check(
                'app.key',
                is_string($key) && strlen($key) >= 32,
                'Der Anwendungsschlüssel ist gesetzt.',
                'APP_KEY fehlt oder ist zu kurz.',
                'fail',
            ),
            $this->check(
                'app.debug',
                ! $production || config('app.debug') === false,
                'Debug ist deaktiviert oder die Umgebung ist nicht Produktion.',
                'APP_DEBUG darf in der Produktion nicht aktiv sein.',
                'fail',
            ),
            $this->check(
                'app.demo_mode',
                ! $production || config('app.demo_mode') === false,
                'Der Demo-Modus ist deaktiviert oder die Umgebung ist nicht Produktion.',
                'APP_DEMO_MODE darf in der Produktion nicht aktiv sein.',
                'fail',
            ),
            $this->check(
                'app.https',
                ! $production || str_starts_with((string) config('app.url'), 'https://'),
                'Die URL verwendet HTTPS oder die Umgebung ist nicht Produktion.',
                'APP_URL muss in der Produktion HTTPS verwenden.',
                'fail',
            ),
            $this->check(
                'session.secure',
                ! $production || (
                    config('session.secure') === true
                    && config('session.http_only') === true
                ),
                'Session-Cookies sind abgesichert oder die Umgebung ist nicht Produktion.',
                'Produktions-Cookies müssen Secure und HttpOnly sein.',
                'fail',
            ),
            $this->check(
                'runtime.redis',
                ! $production || (
                    config('queue.default') === 'redis'
                    && config('cache.default') === 'redis'
                    && config('session.driver') === 'redis'
                ),
                'Redis ist für die Produktionsdienste aktiv oder die Umgebung ist nicht Produktion.',
                'Queue, Cache und Sessions müssen in der Produktion Redis verwenden.',
                'fail',
            ),
            $this->check(
                'storage.private',
                ! $production || (
                    is_array($privateDisk)
                    && ($privateDisk['driver'] ?? null) === 's3'
                    && ($privateDisk['visibility'] ?? null) === 'private'
                    && ($privateDisk['throw'] ?? null) === true
                ),
                'Der private Produktionsspeicher ist abgesichert oder die Umgebung ist nicht Produktion.',
                'Der private Produktionsspeicher muss S3-kompatibel, private und throw=true sein.',
                'fail',
            ),
            $this->check(
                'logging.structured',
                ! $production || (
                    config('logging.default') === 'stderr'
                    && config('logging.channels.stderr.formatter')
                        === config('operations.expected_json_log_formatter')
                ),
                'Strukturierte Produktionslogs sind aktiv oder die Umgebung ist nicht Produktion.',
                'Für die Produktion fehlen strukturierte JSON-Logs auf stderr.',
            ),
            $this->check(
                'clamav.configured',
                filled(config('services.clamav.host'))
                    && (int) config('services.clamav.port') > 0
                    && (int) config('services.clamav.timeout') > 0,
                'ClamAV ist konfiguriert.',
                'ClamAV-Host, Port oder Timeout fehlen.',
                'fail',
            ),
            $this->check(
                'stripe.configured',
                $this->stripeConfigurationIsComplete(),
                'Stripe-Schlüssel, Webhook und alle aktiven Launchpakete sind lokal zugeordnet.',
                'Stripe-Schlüssel, Webhook oder Product-/Price-IDs aktiver Launchpakete fehlen.',
                $production ? 'fail' : 'warn',
            ),
            $this->check(
                'zammad.configured',
                config('services.zammad.enabled') === true
                    && ZammadEndpoint::secureBaseUrl(config('services.zammad.url')) !== null
                    && filled(config('services.zammad.token'))
                    && filled(config('services.zammad.group'))
                    && strlen((string) config('services.zammad.webhook_secret')) >= 32,
                'Zammad verwendet eine sichere HTTPS-URL und vollständige Zugangsdaten.',
                'Zammad benötigt eine sichere HTTPS-URL, Gruppe, Token und ein Webhook-Secret mit mindestens 32 Zeichen.',
                $production ? 'fail' : 'warn',
            ),
            $this->check(
                'retention.approved',
                is_array($retention)
                    && $retention !== []
                    && collect($retention)->every(
                        static fn (mixed $days): bool => is_int($days) && $days > 0,
                    ),
                'Für alle automatischen Löschregeln sind Fristen gesetzt.',
                'Mindestens eine Aufbewahrungsfrist ist deaktiviert oder noch nicht freigegeben.',
            ),
            $this->evidenceCheck(
                'backup.restore_drill',
                $gates['backup_restore_verified_at'] ?? null,
                'Ein erfolgreicher Restore-Drill ist referenziert.',
            ),
            $this->evidenceCheck(
                'security.review',
                $gates['security_review_reference'] ?? null,
                'Die Sicherheitsprüfung ist referenziert.',
            ),
            $this->evidenceCheck(
                'dpo.approval',
                $gates['dpo_approval_reference'] ?? null,
                'Die Datenschutzfreigabe ist referenziert.',
            ),
            $this->evidenceCheck(
                'legal.approval',
                $gates['legal_approval_reference'] ?? null,
                'Die rechtliche Freigabe ist referenziert.',
            ),
            $this->evidenceCheck(
                'pilot.owner',
                $gates['pilot_owner'] ?? null,
                'Für den Pilotbetrieb ist eine verantwortliche Person benannt.',
            ),
        ];
    }

    /**
     * @return list<array{id: string, status: 'pass'|'warn'|'fail', message: string}>
     */
    private function activeChecks(ClamAvScanner $scanner): array
    {
        return [
            $this->probe('probe.database', 'Die Datenbank ist erreichbar.', function (): void {
                DB::select('select 1');
            }),
            $this->probe('probe.private_storage', 'Privater Speicher kann schreiben, lesen und löschen.', function (): void {
                $disk = Storage::disk('private');
                $path = 'readiness/'.Str::uuid().'.txt';

                try {
                    if (! $disk->put($path, 'erin-readiness-probe')) {
                        throw new \RuntimeException('Schreibvorgang fehlgeschlagen.');
                    }

                    if ($disk->get($path) !== 'erin-readiness-probe') {
                        throw new \RuntimeException('Leseprüfung lieferte unerwartete Daten.');
                    }
                } finally {
                    $disk->delete($path);
                }
            }),
            $this->probe('probe.clamav', 'ClamAV akzeptiert eine saubere Testdatei.', function () use ($scanner): void {
                $stream = fopen('php://temp', 'w+b');
                if (! is_resource($stream)) {
                    throw new \RuntimeException('Temporärer Prüfstream konnte nicht erstellt werden.');
                }

                try {
                    fwrite($stream, 'Erin operational readiness probe');
                    rewind($stream);
                    if ($scanner->scan($stream) !== 'clean') {
                        throw new \RuntimeException('ClamAV meldet die Testdatei nicht als sauber.');
                    }
                } finally {
                    fclose($stream);
                }
            }),
        ];
    }

    /**
     * @return array{id: string, status: 'pass'|'warn'|'fail', message: string}
     */
    private function probe(string $id, string $success, callable $callback): array
    {
        try {
            $callback();

            return ['id' => $id, 'status' => 'pass', 'message' => $success];
        } catch (Throwable $exception) {
            Log::warning('Aktive Readiness-Prüfung fehlgeschlagen.', [
                'check' => $id,
                'exception_class' => $exception::class,
            ]);

            return [
                'id' => $id,
                'status' => 'fail',
                'message' => 'Die aktive Prüfung ist fehlgeschlagen; nur die technische Fehlerklasse wurde protokolliert.',
            ];
        }
    }

    private function stripeConfigurationIsComplete(): bool
    {
        if (
            ! filled(config('cashier.key'))
            || ! filled(config('cashier.secret'))
            || ! str_starts_with((string) config('cashier.webhook.secret'), 'whsec_')
        ) {
            return false;
        }

        try {
            $plans = Plan::query()
                ->where('is_active', true)
                ->where('is_enterprise', false)
                ->get(['stripe_product_id', 'stripe_price_id']);
        } catch (Throwable $exception) {
            Log::warning('Lokale Stripe-Readiness-Prüfung fehlgeschlagen.', [
                'exception_class' => $exception::class,
            ]);

            return false;
        }

        return $plans->isNotEmpty() && $plans->every(
            static fn (Plan $plan): bool => str_starts_with(
                (string) $plan->stripe_product_id,
                'prod_',
            ) && str_starts_with((string) $plan->stripe_price_id, 'price_'),
        );
    }

    /**
     * @return array{id: string, status: 'pass'|'warn'|'fail', message: string}
     */
    private function evidenceCheck(string $id, mixed $value, string $success): array
    {
        return $this->check(
            $id,
            is_string($value) && trim($value) !== '',
            $success,
            'Es ist noch keine belastbare Freigabe oder Evidenz hinterlegt.',
        );
    }

    /**
     * @param  'warn'|'fail'  $failureStatus
     * @return array{id: string, status: 'pass'|'warn'|'fail', message: string}
     */
    private function check(
        string $id,
        bool $condition,
        string $success,
        string $failure,
        string $failureStatus = 'warn',
    ): array {
        return [
            'id' => $id,
            'status' => $condition ? 'pass' : $failureStatus,
            'message' => $condition ? $success : $failure,
        ];
    }

    /**
     * @param  list<array{id: string, status: 'pass'|'warn'|'fail', message: string}>  $checks
     */
    private function count(array $checks, string $status): int
    {
        return collect($checks)->where('status', $status)->count();
    }
}
