<?php

use App\Contracts\StripeBillingChangeGateway;
use App\Enums\CompanyMemberRole;
use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Listeners\SyncStripePurchase;
use App\Models\BillingChangeIntent;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CompanyUsagePeriod;
use App\Models\EntitlementLedger;
use App\Models\IntegrationReceipt;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\StripeEnvironment;
use App\Services\Billing\StripePurchaseSignature;
use App\Services\Billing\SubscriptionChangePolicy;
use App\Services\Platform\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Cashier\Events\WebhookReceived;
use Stripe\Subscription;
use Tests\Support\ErinStripeBillingChangeGateway;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Company, 2: Plan}
 */
function erinAdversarialBillingCompany(
    CompanyMemberRole $role = CompanyMemberRole::Owner,
): array {
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_adversarial_current',
        'stripe_price_id' => 'price_adversarial_current',
        'price_cents' => 299_900,
        'term_months' => 2,
    ]);
    $user = User::factory()->create([
        'role' => UserRole::Company,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'onboarding_completed_at' => now(),
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => $plan->getKey(),
        'status' => CompanyStatus::Active,
        'subscription_status' => 'active',
        'stripe_id' => 'cus_adversarial_company',
        'stripe_subscription_id' => 'sub_adversarial_company',
        'subscription_started_at' => now()->startOfDay(),
        'subscription_renews_at' => now()->addMonths(2)->startOfDay(),
    ]);
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => $role,
        'accepted_at' => now(),
    ]);
    $company->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_adversarial_company',
        'stripe_status' => 'active',
        'stripe_price' => $plan->stripe_price_id,
        'quantity' => 1,
    ]);

    return [$user, $company, $plan];
}

/**
 * @return array<string, mixed>
 */
function erinSignedVisaPurchasePayload(
    Company $company,
    string $eventId,
    int $credits = 5,
): array {
    $priceId = 'price_adversarial_visa';

    return [
        'id' => $eventId,
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'data' => ['object' => [
            'id' => 'cs_'.$eventId,
            'mode' => 'payment',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_'.$eventId,
            'metadata' => [
                'purchase_type' => 'visa_credits',
                'company_id' => (string) $company->getKey(),
                'credits' => (string) $credits,
                'price_id' => $priceId,
                'erin_signature_version' => StripePurchaseSignature::VERSION,
                'erin_purchase_signature' => app(
                    StripePurchaseSignature::class,
                )->sign((int) $company->getKey(), $credits, $priceId),
            ],
        ]],
    ];
}

beforeEach(function () {
    config()->set('cashier.secret', 'sk_test_billing_adversarial');
    RateLimiter::clear('billing-adversarial');
});

it('classifies Stripe event modes fail closed', function (
    string $secret,
    mixed $livemode,
    bool $accepted,
) {
    config()->set('cashier.secret', $secret);

    expect(app(StripeEnvironment::class)->acceptsEventMode($livemode))
        ->toBe($accepted);
})->with([
    'Testereignis mit Testschlüssel' => ['sk_test_example', false, true],
    'Liveereignis mit Testschlüssel' => ['sk_test_example', true, false],
    'Liveereignis mit Liveschlüssel' => ['sk_live_example', true, true],
    'Testereignis mit Liveschlüssel' => ['sk_live_example', false, false],
    'Unbekannter Schlüssel' => ['secret_example', false, false],
    'Fehlender boolescher Modus' => ['sk_test_example', null, false],
    'String statt booleschem Modus' => ['sk_test_example', 'false', false],
]);

it('detects every security-relevant mutation of signed visa purchase metadata', function (
    Closure $mutate,
) {
    $company = Company::factory()->create();
    $payload = erinSignedVisaPurchasePayload(
        $company,
        'evt_adversarial_tampered_'.fake()->unique()->numerify('#####'),
    );
    $mutate($payload);

    app(SyncStripePurchase::class)->handle(new WebhookReceived($payload));

    expect(EntitlementLedger::query()->count())->toBe(0)
        ->and(IntegrationReceipt::query()->count())->toBe(0);
})->with([
    'Credit-Anzahl manipuliert' => [
        function (array &$payload): void {
            $payload['data']['object']['metadata']['credits'] = '100';
        },
    ],
    'Firmen-ID manipuliert' => [
        function (array &$payload): void {
            $payload['data']['object']['metadata']['company_id'] = '999999';
        },
    ],
    'Price-ID manipuliert' => [
        function (array &$payload): void {
            $payload['data']['object']['metadata']['price_id'] = 'price_attacker';
        },
    ],
    'Signatur entfernt' => [
        function (array &$payload): void {
            unset($payload['data']['object']['metadata']['erin_purchase_signature']);
        },
    ],
    'Falscher Stripe-Modus' => [
        function (array &$payload): void {
            $payload['livemode'] = true;
        },
    ],
    'Zahlung nicht abgeschlossen' => [
        function (array &$payload): void {
            $payload['data']['object']['payment_status'] = 'unpaid';
        },
    ],
    'Falscher Checkout-Modus' => [
        function (array &$payload): void {
            $payload['data']['object']['mode'] = 'subscription';
        },
    ],
]);

it('rejects forged and ambiguous purchase signature fields', function (
    array $changes,
) {
    $company = Company::factory()->create();
    $payload = erinSignedVisaPurchasePayload(
        $company,
        'evt_adversarial_signature_fields',
    );
    $metadata = $payload['data']['object']['metadata'];

    foreach ($changes as $key => $value) {
        if ($value === '__unset__') {
            unset($metadata[$key]);
        } else {
            $metadata[$key] = $value;
        }
    }

    expect(app(StripePurchaseSignature::class)->verify($metadata))->toBeFalse();
})->with([
    'negative Credits' => [['credits' => '-1']],
    'null Credits' => [['credits' => null]],
    'Fließkomma-Credits' => [['credits' => '1.5']],
    'zu viele Credits' => [['credits' => '101']],
    'ungültige Firmen-ID' => [['company_id' => '1 OR 1=1']],
    'falsche Signaturversion' => [['erin_signature_version' => 'v2']],
    'ungültiges Price-Format' => [['price_id' => 'prod_not_a_price']],
    'gekürzte Signatur' => [['erin_purchase_signature' => 'abc']],
    'fehlende Signatur' => [['erin_purchase_signature' => '__unset__']],
]);

it('uses included visa credits before non-expiring purchases and never uses expired credits', function () {
    $plan = Plan::factory()->create([
        'visa_credits_per_term' => 1,
        'term_months' => 2,
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => $plan->getKey(),
        'status' => CompanyStatus::Active,
        'subscription_status' => 'active',
        'subscription_started_at' => now()->startOfDay(),
        'subscription_renews_at' => now()->addMonths(2)->startOfDay(),
    ]);
    $entitlements = app(EntitlementService::class);
    $firstPurchase = $entitlements->grantPurchasedVisaCredits(
        $company,
        2,
        'pi_adversarial_visa',
    );
    $duplicatePurchase = $entitlements->grantPurchasedVisaCredits(
        $company,
        2,
        'pi_adversarial_visa',
    );
    EntitlementLedger::query()->create([
        'company_id' => $company->getKey(),
        'resource' => 'visa',
        'amount' => 50,
        'source' => 'expired_test_credit',
        'expires_at' => now()->subSecond(),
    ]);

    $entitlements->consumeVisaCredit($company, 1);
    $entitlements->consumeVisaCredit($company, 2);
    $entitlements->consumeVisaCredit($company, 3);

    $usage = CompanyUsagePeriod::query()->sole();
    expect($firstPurchase->is($duplicatePurchase))->toBeTrue()
        ->and($usage->visa_credits_used)->toBe(1)
        ->and(EntitlementLedger::query()
            ->where('source', 'stripe_purchase')
            ->sum('amount'))->toEqual(2)
        ->and(EntitlementLedger::query()
            ->where('source', 'visa_case')
            ->sum('amount'))->toEqual(-2)
        ->and(fn () => $entitlements->consumeVisaCredit($company, 4))
        ->toThrow(DomainException::class, 'kein Visumpaket-Kontingent');
});

it('blocks seat and visa price-role collisions in controllers before any Stripe mutation', function (
    string $route,
    string $setting,
    string $configuration,
    array $payload,
) {
    [$user, $company, $plan] = erinAdversarialBillingCompany();
    config()->set($configuration, $plan->stripe_price_id);
    app(PlatformSettings::class)->put($setting, true, 'billing');
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->post(
        route($route),
        $payload,
    ))->toThrow(LogicException::class, 'zugleich')
        ->and($company->billingSubscription()?->items()->count())
        ->toBe(0)
        ->and(EntitlementLedger::query()
            ->where('company_id', $company->getKey())
            ->doesntExist())->toBeTrue();
})->with([
    'recruiter seat' => [
        'employer.billing.seats',
        'billing.seat_addon_enabled',
        'services.stripe.seat_price_id',
        ['quantity' => 2],
    ],
    'visa package' => [
        'employer.billing.visa-credits',
        'billing.visa_credit_enabled',
        'services.stripe.visa_price_id',
        ['credits' => 5],
    ],
]);

it('shows the localized support warning for a terminal manual-review plan change', function (
    string $locale,
    string $expectedWarning,
) {
    [$user, $company, $current] = erinAdversarialBillingCompany();
    $user->forceFill(['locale' => $locale])->save();
    $target = Plan::factory()->create([
        'price_cents' => 199_900,
        'term_months' => 1,
        'stripe_product_id' => 'prod_manual_warning_target',
        'stripe_price_id' => 'price_manual_warning_target',
    ]);
    $start = now()->startOfDay()->getTimestamp();
    $end = now()->addMonths(2)->startOfDay()->getTimestamp();
    $subscription = Subscription::constructFrom([
        'id' => $company->stripe_subscription_id,
        'object' => 'subscription',
        'customer' => $company->stripe_id,
        'status' => 'active',
        'schedule' => null,
        'items' => ['data' => [[
            'id' => 'si_manual_warning_base',
            'quantity' => 1,
            'current_period_start' => $start,
            'current_period_end' => $end,
            'price' => [
                'id' => $current->stripe_price_id,
                'object' => 'price',
                'product' => $current->stripe_product_id,
            ],
        ]]],
    ]);
    app()->instance(
        StripeBillingChangeGateway::class,
        new ErinStripeBillingChangeGateway($subscription),
    );
    BillingChangeIntent::query()->create([
        'public_id' => fake()->uuid(),
        'company_id' => $company->getKey(),
        'from_plan_id' => $current->getKey(),
        'to_plan_id' => $target->getKey(),
        'requested_by' => $user->getKey(),
        'change_type' => 'downgrade',
        'status' => 'manual_review',
        'active_company_key' => 'company:'.$company->getKey(),
        'stripe_subscription_id' => $company->stripe_subscription_id,
        'from_stripe_price_id' => $current->stripe_price_id,
        'to_stripe_price_id' => $target->stripe_price_id,
        'stripe_idempotency_key' => 'erin-manual-warning',
        'context' => ['remote_operations' => []],
        'attempts' => 2,
        'last_error' => 'Manuelle Prüfung erforderlich.',
        'effective_at' => now()->addMonth(),
    ]);

    $this->actingAs($user)
        ->post(route('employer.billing.change', $target))
        ->assertRedirect()
        ->assertSessionHas(
            'warning',
            $expectedWarning,
        );
})->with([
    'German' => [
        'de',
        'Der Tarifwechsel wurde bei Stripe extern verändert und nicht automatisch übernommen. Der Support wurde zur manuellen Prüfung vorgemerkt.',
    ],
    'English' => [
        'en',
        'The plan change was modified outside Erin in Stripe and was not applied automatically. Support has been notified for manual review.',
    ],
]);

it('calculates upgrades downgrades and cancellation boundaries without provider calls', function () {
    $policy = app(SubscriptionChangePolicy::class);
    $basic = Plan::factory()->create([
        'price_cents' => 299_900,
        'term_months' => 2,
        'stripe_price_id' => 'price_policy_basic',
        'is_active' => true,
        'is_enterprise' => false,
    ]);
    $business = Plan::factory()->create([
        'price_cents' => 349_900,
        'term_months' => 4,
        'stripe_price_id' => 'price_policy_business',
        'is_active' => true,
        'is_enterprise' => false,
    ]);
    $renewsAt = CarbonImmutable::parse('2026-08-31 12:00:00', 'Europe/Berlin');

    expect($policy->isUpgrade($basic, $business))->toBeTrue()
        ->and($policy->isUpgrade($business, $basic))->toBeFalse()
        ->and($policy->cancellationDate(
            $renewsAt,
            2,
            $renewsAt->subDays(14),
        )->equalTo($renewsAt))->toBeTrue()
        ->and($policy->cancellationDate(
            $renewsAt,
            2,
            $renewsAt->subDays(14)->addSecond(),
        )->equalTo(CarbonImmutable::parse(
            '2026-10-31 12:00:00',
            'Europe/Berlin',
        )))->toBeTrue()
        ->and(fn () => $policy->isUpgrade($basic, $basic))
        ->toThrow(DomainException::class, 'bereits aktiv');
});

it('refuses unavailable plan changes before contacting Stripe', function (
    array $attributes,
) {
    $current = Plan::factory()->create([
        'price_cents' => 299_900,
        'stripe_price_id' => 'price_policy_current',
    ]);
    $target = Plan::factory()->create([
        'price_cents' => 349_900,
        'stripe_price_id' => 'price_policy_target',
        ...$attributes,
    ]);

    expect(fn () => app(SubscriptionChangePolicy::class)->isUpgrade(
        $current,
        $target,
    ))->toThrow(DomainException::class);
})->with([
    'inaktives Paket' => [['is_active' => false]],
    'Enterprise' => [['is_enterprise' => true]],
    'fehlende Price-ID' => [['stripe_price_id' => null]],
    'fehlender Preis' => [['price_cents' => null]],
]);

it('validates add-on quantities and rate limits repeated purchase attempts locally', function () {
    [$owner, $company] = erinAdversarialBillingCompany();

    foreach ([0, -1, 101, '1.5', 'unendlich'] as $invalidCredits) {
        $this->actingAs($owner)
            ->withSession(['active_company_id' => $company->getKey()])
            ->post(route('employer.billing.visa-credits'), [
                'credits' => $invalidCredits,
            ])
            ->assertSessionHasErrors('credits');
    }

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.billing.visa-credits'), ['credits' => 0])
        ->assertTooManyRequests();
});

it('prevents viewer roles from starting any billing mutation', function (
    string $routeName,
    array $parameters,
) {
    [$viewer, $company, $plan] = erinAdversarialBillingCompany(
        CompanyMemberRole::Viewer,
    );
    $routeParameters = $routeName === 'employer.billing.checkout'
        || $routeName === 'employer.billing.change'
            ? [$plan]
            : [];

    $this->actingAs($viewer)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route($routeName, $routeParameters), $parameters)
        ->assertForbidden();
})->with([
    'Checkout' => ['employer.billing.checkout', []],
    'Tarifwechsel' => ['employer.billing.change', []],
    'Kündigung' => ['employer.billing.cancel', []],
    'Visakauf' => ['employer.billing.visa-credits', ['credits' => 1]],
    'Sitzkauf' => ['employer.billing.seats', ['quantity' => 1]],
]);
