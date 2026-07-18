<?php

use App\Contracts\StripeCatalogGateway;
use App\Models\Plan;
use App\Models\StripeAddonPrice;
use App\Services\Billing\StripeSubscriptionItemClassifier;
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

it('keeps historical recruiter seat Prices allowed after a configured rotation', function () {
    config()->set('cashier.secret', 'sk_test_addon_rotation');
    config()->set(
        'services.stripe.seat_product_id',
        'prod_recruiter_seat_v1',
    );
    config()->set(
        'services.stripe.seat_price_id',
        'price_recruiter_seat_v1',
    );
    app()->instance(StripeCatalogGateway::class, new ErinStripeCatalogGateway);
    $plan = Plan::factory()->create([
        'slug' => 'addon-rotation-plan',
        'stripe_product_id' => 'prod_addon_rotation_base',
        'stripe_price_id' => 'price_addon_rotation_base',
    ]);

    expect(Artisan::call('erin:stripe:sync-plans', [
        '--plan' => [$plan->slug],
        '--apply' => true,
    ]))->toBe(0);

    config()->set(
        'services.stripe.seat_product_id',
        'prod_recruiter_seat_v2',
    );
    config()->set(
        'services.stripe.seat_price_id',
        'price_recruiter_seat_v2',
    );
    expect(Artisan::call('erin:stripe:sync-plans', [
        '--plan' => [$plan->slug],
        '--apply' => true,
    ]))->toBe(0);

    $old = StripeAddonPrice::query()
        ->where('stripe_price_id', 'price_recruiter_seat_v1')
        ->sole();
    $current = StripeAddonPrice::query()
        ->where('stripe_price_id', 'price_recruiter_seat_v2')
        ->sole();
    expect($old->is_enabled)->toBeTrue()
        ->and($old->retired_at)->not->toBeNull()
        ->and($current->is_enabled)->toBeTrue()
        ->and($current->retired_at)->toBeNull();

    $classification = app(StripeSubscriptionItemClassifier::class)->classify([
        [
            'id' => 'si_addon_rotation_base',
            'quantity' => 1,
            'price' => [
                'id' => $plan->stripe_price_id,
                'product' => $plan->stripe_product_id,
            ],
        ],
        [
            'id' => 'si_addon_rotation_old',
            'quantity' => 2,
            'price' => [
                'id' => $old->stripe_price_id,
                'product' => $old->stripe_product_id,
            ],
        ],
    ]);
    expect($classification['base_plan']?->is($plan))->toBeTrue()
        ->and($classification['add_ons'])->toHaveCount(1)
        ->and($classification['add_ons'][0]['price'])
        ->toBe('price_recruiter_seat_v1');
});

it('fails closed when a configured add-on Price contradicts its stored Product', function () {
    config()->set('cashier.secret', 'sk_test_addon_conflict');
    config()->set(
        'services.stripe.seat_product_id',
        'prod_recruiter_seat_conflict_new',
    );
    config()->set(
        'services.stripe.seat_price_id',
        'price_recruiter_seat_conflict',
    );
    StripeAddonPrice::query()->create([
        'code' => 'recruiter_seat',
        'stripe_product_id' => 'prod_recruiter_seat_conflict_original',
        'stripe_price_id' => 'price_recruiter_seat_conflict',
        'is_enabled' => true,
        'activated_at' => now(),
    ]);
    $gateway = new ErinStripeCatalogGateway;
    app()->instance(StripeCatalogGateway::class, $gateway);
    $plan = Plan::factory()->create([
        'slug' => 'addon-conflict-plan',
        'stripe_product_id' => null,
        'stripe_price_id' => null,
    ]);

    $exitCode = Artisan::call('erin:stripe:sync-plans', [
        '--plan' => [$plan->slug],
        '--apply' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('widersprüchlich registriert')
        ->and($gateway->productCalls)->toBeEmpty()
        ->and($gateway->priceCalls)->toBeEmpty()
        ->and($plan->fresh()?->stripe_product_id)->toBeNull()
        ->and(StripeAddonPrice::query()
            ->where(
                'stripe_price_id',
                'price_recruiter_seat_conflict',
            )
            ->value('stripe_product_id'))
        ->toBe('prod_recruiter_seat_conflict_original');
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

it('refuses ambiguous Stripe secrets before any catalog mutation', function (
    string $secret,
) {
    config()->set('cashier.secret', $secret);
    $gateway = new ErinStripeCatalogGateway;
    app()->instance(StripeCatalogGateway::class, $gateway);
    $plan = Plan::factory()->create([
        'slug' => 'ambiguous-key-guard',
        'stripe_product_id' => null,
        'stripe_price_id' => null,
    ]);

    $exitCode = Artisan::call('erin:stripe:sync-plans', [
        '--plan' => [$plan->slug],
        '--apply' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain('eindeutig erkannten Test- oder Live-Secret-Key')
        ->not->toContain($secret)
        ->and($gateway->productCalls)->toBeEmpty()
        ->and($gateway->priceCalls)->toBeEmpty()
        ->and($plan->fresh()?->stripe_product_id)->toBeNull();
})->with([
    'falscher Prefix' => 'secret_not_stripe',
    'nur Publishable Key' => 'pk_test_not_a_secret',
    'abgeschnittener Test-Key' => 'sk_test',
]);

it('does not leak Stripe timeout rate-limit or server-error details', function (
    string $providerFailure,
) {
    config()->set('cashier.secret', 'sk_test_catalog_failure');
    $gateway = new class($providerFailure) extends ErinStripeCatalogGateway
    {
        public function __construct(private readonly string $failure)
        {
            parent::__construct();
        }

        public function createProduct(
            array $parameters,
            string $idempotencyKey,
        ): string {
            throw new RuntimeException($this->failure);
        }
    };
    app()->instance(StripeCatalogGateway::class, $gateway);
    $plan = Plan::factory()->create([
        'slug' => 'provider-failure',
        'stripe_product_id' => null,
        'stripe_price_id' => null,
    ]);

    $exitCode = Artisan::call('erin:stripe:sync-plans', [
        '--plan' => [$plan->slug],
        '--apply' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain('Details stehen ausschließlich im geschützten Log')
        ->not->toContain($providerFailure)
        ->not->toContain('sk_test_provider_secret')
        ->and($plan->fresh()?->stripe_product_id)->toBeNull()
        ->and($plan->fresh()?->stripe_price_id)->toBeNull();
})->with([
    'Timeout' => 'Timeout; sk_test_provider_secret',
    'Rate Limit' => 'HTTP 429; sk_test_provider_secret',
    'Serverfehler' => 'HTTP 500; sk_test_provider_secret',
]);
