<?php

namespace App\Services\Billing;

use App\Models\Plan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * @phpstan-type ReadinessStatus 'passed'|'warning'|'failed'
 * @phpstan-type ReadinessCheck array{
 *     label: string,
 *     status: ReadinessStatus,
 *     message: string
 * }
 * @phpstan-type StripePriceSnapshot array{
 *     id: string|null,
 *     livemode: bool,
 *     active: bool,
 *     currency: string|null,
 *     unit_amount: int|null,
 *     product_id: string|null,
 *     recurring: array{interval: string|null, interval_count: int|null}|null
 * }
 * @phpstan-type StripeWebhookEndpointSnapshot array{
 *     livemode: bool,
 *     status: string|null,
 *     scheme: string|null,
 *     host: string|null,
 *     port: int|null,
 *     path: string,
 *     has_query: bool,
 *     has_fragment: bool,
 *     has_credentials: bool,
 *     enabled_events: list<string>
 * }
 * @phpstan-type WebhookTarget array{
 *     scheme: 'https',
 *     host: string,
 *     port: int,
 *     path: string
 * }
 */
class StripeStagingReadiness
{
    /** @var list<ReadinessCheck> */
    private array $checks = [];

    public function __construct(
        private readonly StripeReadinessProbe $probe,
    ) {}

    /**
     * @return array{
     *     mode: 'test'|'live'|'unknown',
     *     remote_requested: bool,
     *     remote_verified: bool,
     *     ready: bool,
     *     checks: list<ReadinessCheck>
     * }
     */
    public function inspect(bool $remote = false): array
    {
        $this->checks = [];

        $publishableMode = $this->keyMode(
            (string) config('cashier.key'),
            'pk',
        );
        $secretMode = $this->keyMode(
            (string) config('cashier.secret'),
            'sk',
        );
        $testKeys = $publishableMode === 'test' && $secretMode === 'test';

        $this->add(
            'Stripe-Schlüssel',
            $testKeys ? 'passed' : 'failed',
            $testKeys
                ? 'Publishable- und Secret-Key gehören beide zum Testmodus.'
                : 'Für die Staging-Abnahme werden zusammengehörige Stripe-Testschlüssel benötigt.',
        );
        $this->add(
            'Webhook-Signatur',
            Str::startsWith(
                (string) config('cashier.webhook.secret'),
                'whsec_',
            ) ? 'passed' : 'failed',
            Str::startsWith(
                (string) config('cashier.webhook.secret'),
                'whsec_',
            )
                ? 'Ein Stripe-Webhook-Secret ist konfiguriert.'
                : 'STRIPE_WEBHOOK_SECRET fehlt oder besitzt kein gültiges Format.',
        );
        $this->checkWebhookEvents();
        $this->add(
            'Abrechnungswährung',
            Str::lower((string) config('cashier.currency')) === 'eur'
                ? 'passed'
                : 'failed',
            Str::lower((string) config('cashier.currency')) === 'eur'
                ? 'Cashier rechnet in EUR ab.'
                : 'CASHIER_CURRENCY muss für Erin auf EUR gesetzt sein.',
        );
        $this->checkApplicationUrl();

        $plans = Plan::query()
            ->where('is_active', true)
            ->where('is_enterprise', false)
            ->orderBy('price_cents')
            ->get();
        $this->add(
            'Launchpakete',
            $plans->isNotEmpty() ? 'passed' : 'failed',
            $plans->isNotEmpty()
                ? "{$plans->count()} bezahlte Launchpakete werden geprüft."
                : 'Es ist kein aktives, direkt bezahlbares Paket vorhanden.',
        );

        $locallyValidPlans = $plans
            ->filter(fn (Plan $plan): bool => $this->checkPlan($plan))
            ->values();
        $this->checkAddOnConfiguration('Zusätzlicher Recruiter-Sitz', 'seat_price_id');
        $this->checkAddOnConfiguration('Zusätzliches Visumpaket', 'visa_price_id');

        $remoteVerified = false;
        if (! $remote) {
            $this->add(
                'Stripe-API-Probe',
                'warning',
                'Nicht ausgeführt; mit --remote wird das Testkonto ausschließlich lesend geprüft.',
            );
        } elseif (! $testKeys) {
            $this->add(
                'Stripe-API-Probe',
                'failed',
                'Die Remote-Prüfung wurde ohne passende Testschlüssel sicher abgebrochen.',
            );
        } elseif ($locallyValidPlans->count() !== $plans->count()) {
            $this->add(
                'Stripe-API-Probe',
                'failed',
                'Die Remote-Prüfung wurde wegen ungültiger lokaler Paketdaten nicht gestartet.',
            );
        } else {
            $pricesVerified = $this->checkRemotePrices($locallyValidPlans);
            $addOnsVerified = $this->checkRemoteAddOns();
            $webhookVerified = $this->checkRemoteWebhookEndpoint();
            $remoteVerified = $pricesVerified
                && $addOnsVerified
                && $webhookVerified;
        }

        return [
            'mode' => $secretMode,
            'remote_requested' => $remote,
            'remote_verified' => $remoteVerified,
            'ready' => collect($this->checks)
                ->doesntContain(
                    fn (array $check): bool => $check['status'] === 'failed',
                ),
            'checks' => $this->checks,
        ];
    }

    private function checkWebhookEvents(): void
    {
        $configuredEvents = config('cashier.webhook.events', []);
        $configured = collect(
            is_array($configuredEvents) ? $configuredEvents : [],
        )
            ->filter(fn (mixed $event): bool => is_string($event))
            ->values();
        $missing = collect($this->requiredWebhookEvents())->diff($configured);

        $this->add(
            'Lokale Webhook-Ereignisse',
            $missing->isEmpty() ? 'passed' : 'failed',
            $missing->isEmpty()
                ? 'Cashier verarbeitet die erforderlichen Checkout- und Subscription-Ereignisse lokal.'
                : 'In der lokalen Cashier-Konfiguration fehlt mindestens ein erforderliches Ereignis.',
        );
    }

    private function checkApplicationUrl(): void
    {
        $publicHttps = $this->expectedWebhookTarget() !== null;

        $this->add(
            'Staging-URL',
            $publicHttps ? 'passed' : 'failed',
            $publicHttps
                ? 'APP_URL ist eine öffentliche HTTPS-Adresse.'
                : 'Für Stripe-Webhooks benötigt Staging eine öffentliche HTTPS-APP_URL.',
        );
    }

    private function checkPlan(Plan $plan): bool
    {
        $problems = collect([
            filled($plan->stripe_product_id)
                && Str::startsWith((string) $plan->stripe_product_id, 'prod_')
                    ? null
                    : 'Product-ID',
            filled($plan->stripe_price_id)
                && Str::startsWith((string) $plan->stripe_price_id, 'price_')
                    ? null
                    : 'Price-ID',
            $plan->price_cents !== null && $plan->price_cents > 0
                ? null
                : 'Betrag',
            Str::upper($plan->currency) === 'EUR' ? null : 'Währung',
            $plan->term_months !== null
                && $plan->term_months >= 1
                && $plan->term_months <= 12
                    ? null
                    : 'Laufzeit',
        ])->filter()->values();
        $valid = $problems->isEmpty();

        $this->add(
            "Paket {$plan->slug}",
            $valid ? 'passed' : 'failed',
            $valid
                ? 'Lokaler Betrag, Laufzeit sowie Product- und Price-Zuordnung sind vollständig.'
                : 'Ungültig oder fehlend: '.$problems->implode(', ').'.',
        );

        return $valid;
    }

    private function checkAddOnConfiguration(
        string $label,
        string $configurationKey,
    ): void {
        $priceId = config("services.stripe.{$configurationKey}");
        if (blank($priceId)) {
            $this->add(
                $label,
                'warning',
                'Nicht konfiguriert; der zugehörige Zusatzkauf bleibt deaktiviert.',
            );

            return;
        }

        $valid = is_string($priceId) && Str::startsWith($priceId, 'price_');
        $this->add(
            $label,
            $valid ? 'passed' : 'failed',
            $valid
                ? 'Eine Price-ID ist lokal konfiguriert.'
                : 'Die konfigurierte Price-ID besitzt kein gültiges Format.',
        );
    }

    /**
     * @param  Collection<int, Plan>  $plans
     */
    private function checkRemotePrices(Collection $plans): bool
    {
        $allValid = true;

        foreach ($plans as $plan) {
            try {
                $snapshot = $this->probe->retrievePrice(
                    (string) $plan->stripe_price_id,
                );
                $problems = $this->planSnapshotProblems($plan, $snapshot);
                $valid = $problems === [];
                $allValid = $allValid && $valid;
                $this->add(
                    "Stripe-Price {$plan->slug}",
                    $valid ? 'passed' : 'failed',
                    $valid
                        ? 'Testmodus, Aktivität, Betrag, Währung, Laufzeit und Produkt stimmen überein.'
                        : 'Abweichung im Testkonto: '.implode(', ', $problems).'.',
                );
            } catch (Throwable $exception) {
                $allValid = false;
                Log::warning('Lesende Stripe-Price-Prüfung fehlgeschlagen.', [
                    'plan' => $plan->slug,
                    'exception' => $exception::class,
                ]);
                $this->add(
                    "Stripe-Price {$plan->slug}",
                    'failed',
                    'Die Price konnte nicht lesend aus dem Stripe-Testkonto abgerufen werden.',
                );
            }
        }

        return $allValid;
    }

    private function checkRemoteAddOns(): bool
    {
        $seatValid = $this->checkRemoteAddOn(
            'Zusätzlicher Recruiter-Sitz',
            config('services.stripe.seat_price_id'),
            true,
        );
        $visaValid = $this->checkRemoteAddOn(
            'Zusätzliches Visumpaket',
            config('services.stripe.visa_price_id'),
            false,
        );

        return $seatValid && $visaValid;
    }

    private function checkRemoteAddOn(
        string $label,
        mixed $configuredPriceId,
        bool $recurring,
    ): bool {
        if (
            ! is_string($configuredPriceId)
            || ! Str::startsWith($configuredPriceId, 'price_')
        ) {
            return true;
        }

        try {
            $snapshot = $this->probe->retrievePrice($configuredPriceId);
            $valid = ! $snapshot['livemode']
                && $snapshot['active']
                && $snapshot['currency'] === 'eur'
                && ($recurring
                    ? $snapshot['recurring'] !== null
                        && $snapshot['recurring']['interval'] === 'month'
                    : $snapshot['recurring'] === null);
            $this->add(
                "Stripe-Price {$label}",
                $valid ? 'passed' : 'failed',
                $valid
                    ? 'Die Add-on-Price ist im Testmodus aktiv und korrekt abgerechnet.'
                    : 'Die Add-on-Price passt nicht zur erwarteten Abrechnungsart.',
            );

            return $valid;
        } catch (Throwable $exception) {
            Log::warning('Lesende Stripe-Add-on-Prüfung fehlgeschlagen.', [
                'addon' => $label,
                'exception' => $exception::class,
            ]);
            $this->add(
                "Stripe-Price {$label}",
                'failed',
                'Die Add-on-Price konnte nicht lesend aus dem Testkonto abgerufen werden.',
            );

            return false;
        }
    }

    private function checkRemoteWebhookEndpoint(): bool
    {
        $target = $this->expectedWebhookTarget();
        if ($target === null) {
            $this->add(
                'Stripe-Webhook-Endpunkt',
                'failed',
                'Die Remote-Prüfung benötigt eine öffentliche HTTPS-APP_URL und einen gültigen Cashier-Pfad.',
            );

            return false;
        }

        try {
            $endpoints = $this->probe->listWebhookEndpoints();
            $matchingEndpoints = collect($endpoints)
                ->filter(
                    fn (array $endpoint): bool => $this->matchesWebhookTarget(
                        $endpoint,
                        $target,
                    ),
                )
                ->values();

            if ($matchingEndpoints->isEmpty()) {
                $this->add(
                    'Stripe-Webhook-Endpunkt',
                    'failed',
                    'Im Stripe-Testkonto fehlt ein Endpunkt für den erwarteten Cashier-Webhook.',
                );

                return false;
            }

            $validEndpoint = $matchingEndpoints->first(
                fn (array $endpoint): bool => $this->webhookEndpointProblems(
                    $endpoint,
                ) === [],
            );
            if (is_array($validEndpoint)) {
                $this->add(
                    'Stripe-Webhook-Endpunkt',
                    'passed',
                    'Im Stripe-Testkonto ist ein aktiver Endpunkt mit erwartetem Pfad und erforderlichen Ereignissen vorhanden.',
                );

                return true;
            }

            /** @var StripeWebhookEndpointSnapshot $firstEndpoint */
            $firstEndpoint = $matchingEndpoints->first();
            $this->add(
                'Stripe-Webhook-Endpunkt',
                'failed',
                'Abweichung im Testkonto: '.implode(
                    ', ',
                    $this->webhookEndpointProblems($firstEndpoint),
                ).'.',
            );

            return false;
        } catch (Throwable $exception) {
            Log::warning(
                'Lesende Stripe-Webhook-Endpunkt-Prüfung fehlgeschlagen.',
                ['exception' => $exception::class],
            );
            $this->add(
                'Stripe-Webhook-Endpunkt',
                'failed',
                'Die Webhook-Endpunkte konnten nicht lesend aus dem Stripe-Testkonto abgerufen werden.',
            );

            return false;
        }
    }

    /**
     * @param  StripeWebhookEndpointSnapshot  $endpoint
     * @param  WebhookTarget  $target
     */
    private function matchesWebhookTarget(
        array $endpoint,
        array $target,
    ): bool {
        return Str::lower((string) $endpoint['scheme']) === $target['scheme']
            && Str::lower((string) $endpoint['host']) === $target['host']
            && $this->normalizedPort(
                $endpoint['scheme'],
                $endpoint['port'],
            ) === $target['port']
            && $this->normalizedPath($endpoint['path']) === $target['path'];
    }

    /**
     * @param  StripeWebhookEndpointSnapshot  $endpoint
     * @return list<string>
     */
    private function webhookEndpointProblems(array $endpoint): array
    {
        $enabledEvents = $endpoint['enabled_events'];
        $acceptsAllEvents = in_array('*', $enabledEvents, true);
        $missingEvents = $acceptsAllEvents
            ? []
            : array_values(array_diff(
                $this->configuredWebhookEvents(),
                $enabledEvents,
            ));

        return array_values(array_filter([
            ! $endpoint['livemode'] ? null : 'Modus',
            $endpoint['status'] === 'enabled' ? null : 'Status',
            ! $endpoint['has_query']
                && ! $endpoint['has_fragment']
                && ! $endpoint['has_credentials']
                    ? null
                    : 'URL-Zusätze',
            $missingEvents === [] ? null : 'Ereignistypen',
        ]));
    }

    /**
     * @return WebhookTarget|null
     */
    private function expectedWebhookTarget(): ?array
    {
        $appUrl = (string) config('app.url');
        $parts = parse_url($appUrl);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::lower((string) ($parts['host'] ?? ''));
        $cashierPath = trim((string) config('cashier.path'), '/');
        if (
            $scheme !== 'https'
            || $host === ''
            || ! $this->isPublicHost($host)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || $cashierPath === ''
            || preg_match('/^[A-Za-z0-9_\/-]+$/', $cashierPath) !== 1
        ) {
            return null;
        }

        $basePath = is_string($parts['path'] ?? null)
            ? $parts['path']
            : '/';

        return [
            'scheme' => 'https',
            'host' => $host,
            'port' => $this->normalizedPort(
                $scheme,
                is_numeric($parts['port'] ?? null)
                    ? (int) $parts['port']
                    : null,
            ),
            'path' => $this->normalizedPath(
                $basePath.'/'.$cashierPath.'/webhook',
            ),
        ];
    }

    private function isPublicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return str_contains($host, '.')
            && filter_var(
                $host,
                FILTER_VALIDATE_DOMAIN,
                FILTER_FLAG_HOSTNAME,
            ) !== false
            && ! Str::endsWith(
                $host,
                [
                    '.example',
                    '.internal',
                    '.invalid',
                    '.local',
                    '.localhost',
                    '.test',
                ],
            );
    }

    private function normalizedPort(?string $scheme, ?int $port): int
    {
        if ($port !== null) {
            return $port;
        }

        return Str::lower((string) $scheme) === 'https' ? 443 : 80;
    }

    private function normalizedPath(string $path): string
    {
        return '/'.trim(preg_replace('#/+#', '/', $path) ?? '/', '/');
    }

    /**
     * @param  StripePriceSnapshot  $snapshot
     * @return list<string>
     */
    private function planSnapshotProblems(Plan $plan, array $snapshot): array
    {
        return array_values(array_filter([
            $snapshot['id'] === $plan->stripe_price_id ? null : 'Price-ID',
            ! $snapshot['livemode'] ? null : 'Modus',
            $snapshot['active'] ? null : 'Aktivität',
            $snapshot['currency'] === Str::lower($plan->currency)
                ? null
                : 'Währung',
            $snapshot['unit_amount'] === $plan->price_cents ? null : 'Betrag',
            $snapshot['product_id'] === $plan->stripe_product_id
                ? null
                : 'Produkt',
            $snapshot['recurring'] !== null
                && $snapshot['recurring']['interval'] === 'month'
                && $snapshot['recurring']['interval_count']
                    === $plan->term_months
                        ? null
                        : 'Laufzeit',
        ]));
    }

    /**
     * @param  ReadinessStatus  $status
     */
    private function add(string $label, string $status, string $message): void
    {
        $this->checks[] = [
            'label' => $label,
            'status' => $status,
            'message' => $message,
        ];
    }

    /**
     * @return 'test'|'live'|'unknown'
     */
    private function keyMode(string $key, string $keyType): string
    {
        return match (true) {
            Str::startsWith($key, "{$keyType}_test_") => 'test',
            Str::startsWith($key, "{$keyType}_live_") => 'live',
            default => 'unknown',
        };
    }

    /**
     * @return list<string>
     */
    private function requiredWebhookEvents(): array
    {
        return [
            'checkout.session.completed',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ];
    }

    /**
     * @return list<string>
     */
    private function configuredWebhookEvents(): array
    {
        $configured = config('cashier.webhook.events', []);
        if (! is_array($configured)) {
            return $this->requiredWebhookEvents();
        }

        $events = array_values(array_unique(array_filter(
            $configured,
            static fn (mixed $event): bool => is_string($event)
                && $event !== '',
        )));

        return $events === [] ? $this->requiredWebhookEvents() : $events;
    }
}
