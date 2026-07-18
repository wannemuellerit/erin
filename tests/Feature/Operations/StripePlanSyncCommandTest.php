<?php

use App\Contracts\StripeCatalogGateway;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\ErinStripeCatalogGateway;

uses(RefreshDatabase::class);

it('plans missing Stripe catalog entries without changing local or external state', function () {
    $gateway = new ErinStripeCatalogGateway;
    app()->instance(StripeCatalogGateway::class, $gateway);
    $plan = Plan::factory()->create([
        'slug' => 'dry-run',
        'stripe_product_id' => null,
        'stripe_price_id' => null,
    ]);

    $exitCode = Artisan::call('erin:stripe:sync-plans', ['--plan' => [$plan->slug]]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('DRY-RUN')
        ->toContain('Produkt anlegen')
        ->toContain('Preis anlegen')
        ->and($gateway->productCalls)->toBeEmpty()
        ->and($gateway->priceCalls)->toBeEmpty()
        ->and($plan->fresh()?->stripe_product_id)->toBeNull()
        ->and($plan->fresh()?->stripe_price_id)->toBeNull();
});

it('creates test mode products and immutable recurring prices only with apply', function () {
    config()->set('cashier.secret', 'sk_test_command_key');
    $gateway = new ErinStripeCatalogGateway('prod_created', 'price_created');
    app()->instance(StripeCatalogGateway::class, $gateway);
    $plan = Plan::factory()->create([
        'slug' => 'business-command',
        'name' => 'Business',
        'description' => 'Recruiting für wachsende Teams',
        'price_cents' => 349900,
        'currency' => 'EUR',
        'term_months' => 4,
        'stripe_product_id' => null,
        'stripe_price_id' => null,
    ]);

    $exitCode = Artisan::call('erin:stripe:sync-plans', [
        '--plan' => [$plan->slug],
        '--apply' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and($gateway->productCalls)->toHaveCount(1)
        ->and($gateway->productCalls[0]['parameters'])->toMatchArray([
            'name' => 'Business',
            'metadata' => [
                'erin_plan_id' => (string) $plan->getKey(),
                'erin_plan_slug' => 'business-command',
            ],
        ])
        ->and($gateway->productCalls[0]['idempotency_key'])
        ->toBe("erin-plan-{$plan->getKey()}-product-v1")
        ->and($gateway->priceCalls)->toHaveCount(1)
        ->and($gateway->priceCalls[0]['parameters'])->toMatchArray([
            'product' => 'prod_created',
            'currency' => 'eur',
            'unit_amount' => 349900,
            'recurring' => [
                'interval' => 'month',
                'interval_count' => 4,
            ],
        ])
        ->and($plan->fresh()?->stripe_product_id)->toBe('prod_created')
        ->and($plan->fresh()?->stripe_price_id)->toBe('price_created');
});

it('refuses live Stripe mutations outside a known production deployment', function () {
    config()->set('cashier.secret', 'sk_live_command_key');
    config()->set('app.url', 'https://erin.example');
    $gateway = new ErinStripeCatalogGateway;
    app()->instance(StripeCatalogGateway::class, $gateway);
    $plan = Plan::factory()->create([
        'slug' => 'live-guard',
        'stripe_product_id' => null,
        'stripe_price_id' => null,
    ]);

    $exitCode = Artisan::call('erin:stripe:sync-plans', [
        '--plan' => [$plan->slug],
        '--apply' => true,
        '--allow-live' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('APP_ENV=production')
        ->and($gateway->productCalls)->toBeEmpty()
        ->and($gateway->priceCalls)->toBeEmpty()
        ->and($plan->fresh()?->stripe_product_id)->toBeNull();
});

it('refuses non-interactive live Stripe mutations in a known production deployment', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('cashier.secret', 'sk_live_command_key');
    config()->set('app.url', 'https://erin.wannemueller.dev');
    $gateway = new ErinStripeCatalogGateway;
    app()->instance(StripeCatalogGateway::class, $gateway);
    $plan = Plan::factory()->create([
        'slug' => 'live-non-interactive-guard',
        'stripe_product_id' => null,
        'stripe_price_id' => null,
    ]);

    $exitCode = Artisan::call('erin:stripe:sync-plans', [
        '--plan' => [$plan->slug],
        '--apply' => true,
        '--allow-live' => true,
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('--no-interaction wird aus Sicherheitsgründen abgelehnt')
        ->and($gateway->productCalls)->toBeEmpty()
        ->and($gateway->priceCalls)->toBeEmpty()
        ->and($plan->fresh()?->stripe_product_id)->toBeNull();
});

it('rejects an orphaned Price ID instead of guessing its Stripe product', function () {
    $gateway = new ErinStripeCatalogGateway;
    app()->instance(StripeCatalogGateway::class, $gateway);
    $plan = Plan::factory()->create([
        'slug' => 'orphan-price',
        'stripe_product_id' => null,
        'stripe_price_id' => 'price_without_product',
    ]);

    $exitCode = Artisan::call('erin:stripe:sync-plans', ['--plan' => [$plan->slug]]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Price-ID ohne zugehörige Product-ID')
        ->and($gateway->productCalls)->toBeEmpty()
        ->and($gateway->priceCalls)->toBeEmpty();
});
