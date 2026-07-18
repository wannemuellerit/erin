<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\Billing\StripeAddonPriceRegistry;
use App\Services\Billing\StripePlanSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class SyncStripePlansCommand extends Command
{
    protected $signature = 'erin:stripe:sync-plans
        {--plan=* : Nur die angegebenen Paket-Slugs prüfen}
        {--apply : Fehlende Stripe-Produkte und -Preise tatsächlich anlegen}
        {--allow-live : Live-Modus nur zusammen mit Produktionsumgebung und öffentlicher HTTPS-URL erlauben}';

    protected $description = 'Prüft den Stripe-Produktkatalog; ohne --apply bleibt der Befehl immer im sicheren Dry-Run.';

    public function handle(
        StripePlanSynchronizer $synchronizer,
        StripeAddonPriceRegistry $addOnPrices,
    ): int {
        $apply = (bool) $this->option('apply');

        if ($apply && ! $this->canApply()) {
            return self::FAILURE;
        }

        $slugs = collect($this->option('plan'))
            ->filter(fn (mixed $slug): bool => is_string($slug) && $slug !== '')
            ->values();
        $plans = Plan::query()
            ->when(
                $slugs->isNotEmpty(),
                fn ($query) => $query->whereIn('slug', $slugs),
            )
            ->orderByRaw('price_cents is null')
            ->orderBy('price_cents')
            ->get();

        if ($plans->isEmpty()) {
            $this->error('Für die Auswahl wurden keine Pakete gefunden.');

            return self::FAILURE;
        }

        $this->components->info($apply
            ? 'Stripe-Katalogabgleich wird ausdrücklich angewendet.'
            : 'DRY-RUN: Es werden keine Stripe- oder Datenbankwerte verändert.');

        if ($apply) {
            try {
                $addOnPrices->synchronizeConfiguredAddOns();
            } catch (LogicException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            } catch (Throwable $exception) {
                report($exception);
                $this->error(
                    'Die Stripe-Add-on-Price konnte nicht sicher registriert werden.',
                );

                return self::FAILURE;
            }
        }

        $failed = false;
        $rows = [];

        foreach ($plans as $plan) {
            try {
                $result = $synchronizer->synchronize($plan, $apply);
                $rows[] = [
                    $plan->name,
                    $plan->slug,
                    $this->statusLabel($result['status']),
                    $this->actionsLabel($result['actions']),
                ];
            } catch (LogicException $exception) {
                $failed = true;
                $rows[] = [$plan->name, $plan->slug, 'Ungültig', $exception->getMessage()];
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;
                $rows[] = [
                    $plan->name,
                    $plan->slug,
                    'Fehlgeschlagen',
                    'Stripe-Anfrage fehlgeschlagen; Details stehen ausschließlich im geschützten Log.',
                ];
            }
        }

        $this->table(['Paket', 'Slug', 'Status', 'Aktionen'], $rows);

        if (! $apply) {
            $this->newLine();
            $this->line('Zum bewussten Anlegen im Stripe-Testmodus erneut mit --apply ausführen.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function canApply(): bool
    {
        $secret = (string) config('cashier.secret');

        if ($secret === '') {
            $this->error('STRIPE_SECRET_KEY ist nicht konfiguriert.');

            return false;
        }

        if (Str::startsWith($secret, 'sk_test_')) {
            return true;
        }

        if (! Str::startsWith($secret, 'sk_live_')) {
            $this->error(
                'Stripe-Mutationen benötigen einen eindeutig erkannten Test- oder Live-Secret-Key.',
            );

            return false;
        }

        if (! $this->option('allow-live')) {
            $this->error('Live-Mutationen benötigen zusätzlich die ausdrückliche Option --allow-live.');

            return false;
        }

        if (! app()->isProduction() || ! $this->hasKnownProductionUrl()) {
            $this->error(
                'Live-Mutationen sind nur in APP_ENV=production mit einer öffentlichen HTTPS-APP_URL erlaubt.',
            );

            return false;
        }

        if (! $this->input->isInteractive()) {
            $this->error(
                'Live-Mutationen benötigen eine interaktive Bestätigung; --no-interaction wird aus Sicherheitsgründen abgelehnt.',
            );

            return false;
        }

        if (! $this->confirm(
            'Wirklich fehlende Produkte und Preise im Stripe-Livekonto anlegen?',
            false,
        )) {
            $this->warn('Der Live-Abgleich wurde abgebrochen.');

            return false;
        }

        return true;
    }

    private function hasKnownProductionUrl(): bool
    {
        $url = (string) config('app.url');
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;

        return $scheme === 'https'
            && is_string($host)
            && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && ! Str::endsWith($host, ['.test', '.local', '.localhost', '.example']);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'configured' => 'Konfiguriert',
            'planned' => 'Geplant',
            'applied' => 'Angelegt',
            'skipped' => 'Übersprungen',
            default => $status,
        };
    }

    /**
     * @param  list<string>  $actions
     */
    private function actionsLabel(array $actions): string
    {
        if ($actions === []) {
            return 'Keine';
        }

        return collect($actions)
            ->map(fn (string $action): string => match ($action) {
                'create_product' => 'Produkt anlegen',
                'create_price' => 'Preis anlegen',
                default => $action,
            })
            ->implode(', ');
    }
}
