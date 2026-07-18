<?php

use App\Models\Plan;
use App\Services\Billing\StripeReadinessProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\ErinStripeReadinessProbe;

uses(RefreshDatabase::class);

function configureErinStripeStaging(): void
{
    config()->set('app.url', 'https://staging.erin-recruiting.de');
    config()->set('cashier.key', 'pk_test_readiness_public');
    config()->set('cashier.secret', 'sk_test_readiness_secret');
    config()->set('cashier.currency', 'eur');
    config()->set('cashier.webhook.secret', 'whsec_readiness');
    config()->set('cashier.webhook.events', [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ]);
    config()->set('services.stripe.seat_price_id');
    config()->set('services.stripe.visa_price_id');
}

function createErinReadinessPlan(
    string $slug,
    int $amount,
    int $months,
): Plan {
    return Plan::factory()->create([
        'slug' => $slug,
        'price_cents' => $amount,
        'currency' => 'EUR',
        'term_months' => $months,
        'stripe_product_id' => "prod_{$slug}",
        'stripe_price_id' => "price_{$slug}",
    ]);
}

/**
 * @return array{
 *     id: string|null,
 *     livemode: bool,
 *     active: bool,
 *     currency: string|null,
 *     unit_amount: int|null,
 *     product_id: string|null,
 *     recurring: array{interval: string|null, interval_count: int|null}|null
 * }
 */
function erinReadinessPrice(Plan $plan): array
{
    return [
        'id' => $plan->stripe_price_id,
        'livemode' => false,
        'active' => true,
        'currency' => 'eur',
        'unit_amount' => $plan->price_cents,
        'product_id' => $plan->stripe_product_id,
        'recurring' => [
            'interval' => 'month',
            'interval_count' => $plan->term_months,
        ],
    ];
}

/**
 * @param  list<string>|null  $events
 * @return array{
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
 */
function erinReadinessWebhookEndpoint(
    ?array $events = null,
    string $path = '/billing/webhook',
): array {
    return [
        'livemode' => false,
        'status' => 'enabled',
        'scheme' => 'https',
        'host' => 'staging.erin-recruiting.de',
        'port' => null,
        'path' => $path,
        'has_query' => false,
        'has_fragment' => false,
        'has_credentials' => false,
        'enabled_events' => $events ?? [
            'checkout.session.completed',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ],
    ];
}

beforeEach(function () {
    configureErinStripeStaging();
});

it('checks the local Stripe staging configuration without external calls or mutations', function () {
    $secret = (string) config('cashier.secret');
    $plan = createErinReadinessPlan('basic-readiness', 299_900, 2);
    $probe = new ErinStripeReadinessProbe;
    app()->instance(StripeReadinessProbe::class, $probe);

    $exitCode = Artisan::call('erin:stripe:staging-check');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('keine Stripe- oder Datenbankwerte verändert')
        ->toContain('Lokale Webhook-Ereignisse')
        ->toContain('Cashier verarbeitet')
        ->toContain('optionale Remote-Prüfung steht noch aus')
        ->not->toContain('vollständig registriert')
        ->not->toContain($secret)
        ->and($probe->retrieved)->toBeEmpty()
        ->and($probe->webhookListCalls)->toBe(0)
        ->and($plan->fresh()?->stripe_product_id)->toBe('prod_basic-readiness')
        ->and($plan->fresh()?->stripe_price_id)->toBe('price_basic-readiness');
});

it('verifies configured plan prices through read-only test mode probes', function () {
    $basic = createErinReadinessPlan('basic-remote', 299_900, 2);
    $business = createErinReadinessPlan('business-remote', 349_900, 4);
    $probe = new ErinStripeReadinessProbe;
    $probe->prices = [
        (string) $basic->stripe_price_id => erinReadinessPrice($basic),
        (string) $business->stripe_price_id => erinReadinessPrice($business),
    ];
    $probe->webhookEndpoints = [erinReadinessWebhookEndpoint()];
    app()->instance(StripeReadinessProbe::class, $probe);

    $exitCode = Artisan::call('erin:stripe:staging-check', ['--remote' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Webhook-Endpunkt sind bereit')
        ->and($probe->retrieved)->toBe([
            'price_basic-remote',
            'price_business-remote',
        ])
        ->and($probe->webhookListCalls)->toBe(1);
});

it('fails safely when a remote price differs from the local package configuration', function () {
    $plan = createErinReadinessPlan('premium-mismatch', 499_900, 6);
    $probe = new ErinStripeReadinessProbe;
    $snapshot = erinReadinessPrice($plan);
    $snapshot['unit_amount'] = 499_899;
    $snapshot['livemode'] = true;
    $probe->prices[(string) $plan->stripe_price_id] = $snapshot;
    $probe->webhookEndpoints = [erinReadinessWebhookEndpoint()];
    app()->instance(StripeReadinessProbe::class, $probe);
    $secret = (string) config('cashier.secret');

    $exitCode = Artisan::call('erin:stripe:staging-check', ['--remote' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Abweichung im Testkonto')
        ->toContain('Modus')
        ->toContain('Betrag')
        ->not->toContain($secret);
});

it('rejects mixed or live keys before running a remote probe', function () {
    createErinReadinessPlan('live-refusal', 299_900, 2);
    config()->set('cashier.key', 'pk_test_readiness_public');
    config()->set('cashier.secret', 'sk_live_readiness_secret');
    $probe = new ErinStripeReadinessProbe;
    app()->instance(StripeReadinessProbe::class, $probe);

    $exitCode = Artisan::call('erin:stripe:staging-check', ['--remote' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('zusammengehörige Stripe-Testschlüssel')
        ->toContain('sicher abgebrochen')
        ->not->toContain('sk_live_readiness_secret')
        ->and($probe->retrieved)->toBeEmpty()
        ->and($probe->webhookListCalls)->toBe(0);
});

it('fails locally for incomplete package price mappings', function () {
    Plan::factory()->create([
        'slug' => 'incomplete-price-map',
        'stripe_product_id' => null,
        'stripe_price_id' => null,
    ]);
    $probe = new ErinStripeReadinessProbe;
    app()->instance(StripeReadinessProbe::class, $probe);

    $exitCode = Artisan::call('erin:stripe:staging-check');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Product-ID')
        ->toContain('Price-ID')
        ->and($probe->retrieved)->toBeEmpty()
        ->and($probe->webhookListCalls)->toBe(0);
});

it('enforces test keys inside the concrete read-only probe before any network request', function () {
    config()->set('cashier.secret', 'sk_live_never_contact_stripe');

    expect(fn () => app(StripeReadinessProbe::class)->retrievePrice('price_unused'))
        ->toThrow(
            LogicException::class,
            'ausschließlich mit einem Test-Secret-Key',
        );
    expect(fn () => app(StripeReadinessProbe::class)->listWebhookEndpoints())
        ->toThrow(
            LogicException::class,
            'ausschließlich mit einem Test-Secret-Key',
        );
});

it('fails when the expected remote webhook endpoint lacks required events', function () {
    $plan = createErinReadinessPlan('missing-webhook-event', 299_900, 2);
    $probe = new ErinStripeReadinessProbe;
    $probe->prices[(string) $plan->stripe_price_id] = erinReadinessPrice($plan);
    $probe->webhookEndpoints = [erinReadinessWebhookEndpoint([
        'checkout.session.completed',
        'customer.subscription.created',
    ])];
    app()->instance(StripeReadinessProbe::class, $probe);

    $exitCode = Artisan::call('erin:stripe:staging-check', ['--remote' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Stripe-Webhook-Endpunkt')
        ->toContain('Ereignistypen')
        ->and($probe->webhookListCalls)->toBe(1);
});

it('does not expose sensitive URL additions reported by Stripe', function () {
    $plan = createErinReadinessPlan('safe-webhook-output', 299_900, 2);
    $probe = new ErinStripeReadinessProbe;
    $probe->prices[(string) $plan->stripe_price_id] = erinReadinessPrice($plan);
    $sensitiveQuery = 'token=sensitive-webhook-value';
    $endpoint = $probe->normalizeWebhookPayload([
        'livemode' => false,
        'status' => 'enabled',
        'url' => "https://staging.erin-recruiting.de/billing/webhook?{$sensitiveQuery}",
        'enabled_events' => [
            'checkout.session.completed',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ],
    ]);
    $probe->webhookEndpoints = [$endpoint];
    app()->instance(StripeReadinessProbe::class, $probe);

    $exitCode = Artisan::call('erin:stripe:staging-check', ['--remote' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('URL-Zusätze')
        ->not->toContain($sensitiveQuery)
        ->not->toContain('https://staging.erin-recruiting.de')
        ->and(json_encode($endpoint, JSON_THROW_ON_ERROR))
        ->not->toContain($sensitiveQuery);
});

it('requires the exact Cashier webhook path in the Stripe test account', function () {
    $plan = createErinReadinessPlan('wrong-webhook-path', 299_900, 2);
    $probe = new ErinStripeReadinessProbe;
    $probe->prices[(string) $plan->stripe_price_id] = erinReadinessPrice($plan);
    $probe->webhookEndpoints = [
        erinReadinessWebhookEndpoint(path: '/integrations/stripe'),
    ];
    app()->instance(StripeReadinessProbe::class, $probe);

    $exitCode = Artisan::call('erin:stripe:staging-check', ['--remote' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain(
            'fehlt ein Endpunkt für den erwarteten Cashier-Webhook',
        )
        ->not->toContain('/integrations/stripe');
});

it('requires an enabled test mode webhook endpoint', function () {
    $plan = createErinReadinessPlan('disabled-webhook', 299_900, 2);
    $probe = new ErinStripeReadinessProbe;
    $probe->prices[(string) $plan->stripe_price_id] = erinReadinessPrice($plan);
    $endpoint = erinReadinessWebhookEndpoint();
    $endpoint['status'] = 'disabled';
    $endpoint['livemode'] = true;
    $probe->webhookEndpoints = [$endpoint];
    app()->instance(StripeReadinessProbe::class, $probe);

    $exitCode = Artisan::call('erin:stripe:staging-check', ['--remote' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Modus')
        ->toContain('Status');
});

it('fails without listing endpoints when the staging URL is not public HTTPS', function () {
    $plan = createErinReadinessPlan('local-webhook-target', 299_900, 2);
    config()->set('app.url', 'http://localhost:8000?token=never-log-this');
    $probe = new ErinStripeReadinessProbe;
    $probe->prices[(string) $plan->stripe_price_id] = erinReadinessPrice($plan);
    app()->instance(StripeReadinessProbe::class, $probe);

    $exitCode = Artisan::call('erin:stripe:staging-check', ['--remote' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('öffentliche HTTPS-APP_URL')
        ->not->toContain('never-log-this')
        ->and($probe->webhookListCalls)->toBe(0);
});

it('rejects private HTTPS addresses as non-public webhook targets', function () {
    $plan = createErinReadinessPlan('private-webhook-target', 299_900, 2);
    config()->set('app.url', 'https://192.168.20.15');
    $probe = new ErinStripeReadinessProbe;
    $probe->prices[(string) $plan->stripe_price_id] = erinReadinessPrice($plan);
    app()->instance(StripeReadinessProbe::class, $probe);

    $exitCode = Artisan::call('erin:stripe:staging-check', ['--remote' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('öffentliche HTTPS-APP_URL')
        ->not->toContain('192.168.20.15')
        ->and($probe->webhookListCalls)->toBe(0);
});
