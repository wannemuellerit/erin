<?php

use App\Contracts\StripeSubscriptionGateway;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\IntegrationReceipt;
use App\Models\Plan;
use App\Services\Billing\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Subscription;
use Tests\Support\ErinStripeSubscriptionGateway;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $payload
 */
function erinPostSignedStripeWebhook(array $payload, string $secret, bool $valid = true): TestResponse
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = now()->getTimestamp();
    $signature = hash_hmac(
        'sha256',
        $timestamp.'.'.$json,
        $valid ? $secret : 'whsec_wrong',
    );

    return test()->call(
        'POST',
        route('cashier.webhook'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ],
        $json,
    );
}

/**
 * @return array<string, mixed>
 */
function erinStripeSubscriptionPayload(
    string $eventId,
    string $eventType,
    Company $company,
    Plan $plan,
    string $status = 'active',
    ?int $eventCreated = null,
    string $subscriptionId = 'sub_http_acceptance',
    ?int $subscriptionCreated = null,
    ?array $items = null,
    int $subscriptionGeneration = 0,
): array {
    $startsAt = now()->startOfDay();
    $endsAt = $startsAt->copy()->addMonths($plan->term_months ?? 1);
    $items ??= [[
        'id' => 'si_'.$subscriptionId,
        'object' => 'subscription_item',
        'quantity' => 1,
        'current_period_start' => $startsAt->getTimestamp(),
        'current_period_end' => $endsAt->getTimestamp(),
        'price' => [
            'id' => $plan->stripe_price_id,
            'object' => 'price',
            'product' => $plan->stripe_product_id,
        ],
    ]];

    $payload = [
        'id' => $eventId,
        'object' => 'event',
        'type' => $eventType,
        'created' => $eventCreated ?? now()->getTimestamp(),
        'data' => [
            'object' => [
                'id' => $subscriptionId,
                'object' => 'subscription',
                'customer' => $company->stripe_id,
                'created' => $subscriptionCreated ?? $eventCreated ?? now()->getTimestamp(),
                'status' => $status,
                'metadata' => [
                    'type' => 'default',
                    'company_id' => (string) $company->getKey(),
                    'erin_subscription_generation' => (string) $subscriptionGeneration,
                ],
                'trial_end' => null,
                'cancel_at_period_end' => false,
                'current_period_start' => $startsAt->getTimestamp(),
                'current_period_end' => $endsAt->getTimestamp(),
                'ended_at' => $status === 'canceled' ? now()->getTimestamp() : null,
                'items' => ['data' => $items],
            ],
        ],
    ];

    $gateway = app(StripeSubscriptionGateway::class);
    if ($gateway instanceof ErinStripeSubscriptionGateway) {
        $gateway->put($payload['data']['object']);
    }

    return $payload;
}

beforeEach(function () {
    config()->set('cashier.webhook.secret', 'whsec_http_acceptance');
    config()->set('cashier.webhook.tolerance', 300);
    app()->instance(
        StripeSubscriptionGateway::class,
        new ErinStripeSubscriptionGateway,
    );
});

it('accepts a signed Cashier webhook and processes a duplicate only once', function () {
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_http_acceptance',
        'stripe_price_id' => 'price_http_acceptance',
        'term_months' => 4,
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => null,
        'stripe_id' => 'cus_http_acceptance',
        'status' => CompanyStatus::Pending,
        'subscription_status' => 'incomplete',
    ]);
    $payload = erinStripeSubscriptionPayload(
        'evt_http_created',
        'customer.subscription.created',
        $company,
        $plan,
    );

    erinPostSignedStripeWebhook($payload, 'whsec_http_acceptance')->assertOk();
    erinPostSignedStripeWebhook($payload, 'whsec_http_acceptance')->assertOk();
    $company->refresh();

    expect($company->status)->toBe(CompanyStatus::Active)
        ->and($company->subscription_status)->toBe('active')
        ->and($company->current_plan_id)->toBe($plan->getKey())
        ->and($company->subscriptions()->count())->toBe(1)
        ->and($company->subscription('default')?->stripe_status)->toBe('active')
        ->and($company->subscription('default')?->items()->count())->toBe(1)
        ->and(IntegrationReceipt::query()
            ->where('provider', 'stripe:handled')
            ->where('event_id', 'evt_http_created')
            ->where('status', 'processed')
            ->count())->toBe(1);
});

it('rejects a replayed event ID with a changed payload without mutating billing again', function () {
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_http_replay',
        'stripe_price_id' => 'price_http_replay',
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => null,
        'stripe_id' => 'cus_http_replay',
        'status' => CompanyStatus::Pending,
        'subscription_status' => 'incomplete',
    ]);
    $payload = erinStripeSubscriptionPayload(
        'evt_http_replay',
        'customer.subscription.created',
        $company,
        $plan,
    );
    erinPostSignedStripeWebhook($payload, 'whsec_http_acceptance')->assertOk();

    $changedReplay = erinStripeSubscriptionPayload(
        'evt_http_replay',
        'customer.subscription.deleted',
        $company,
        $plan,
        'canceled',
    );
    $this->withoutExceptionHandling();

    expect(
        fn () => erinPostSignedStripeWebhook(
            $changedReplay,
            'whsec_http_acceptance',
        ),
    )->toThrow(RuntimeException::class, 'reused with a different payload');

    expect($company->fresh()?->subscription_status)->toBe('active')
        ->and($company->fresh()?->current_plan_id)->toBe($plan->getKey())
        ->and(IntegrationReceipt::query()
            ->where('provider', 'stripe:handled')
            ->where('event_id', 'evt_http_replay')
            ->where('status', 'processed')
            ->count())->toBe(1);
});

it('can safely retry an identical webhook after a transient canonical lookup failure', function () {
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_http_retry',
        'stripe_price_id' => 'price_http_retry',
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => null,
        'stripe_id' => 'cus_http_retry',
        'status' => CompanyStatus::Pending,
        'subscription_status' => 'incomplete',
    ]);
    $gateway = new class extends ErinStripeSubscriptionGateway
    {
        public bool $available = false;

        public function retrieve(string $subscriptionId): array
        {
            if (! $this->available) {
                throw new RuntimeException('Simulierter temporärer Stripe-Ausfall.');
            }

            return parent::retrieve($subscriptionId);
        }
    };
    app()->instance(StripeSubscriptionGateway::class, $gateway);
    $payload = erinStripeSubscriptionPayload(
        'evt_http_retry',
        'customer.subscription.created',
        $company,
        $plan,
    );
    $this->withoutExceptionHandling();

    expect(
        fn () => erinPostSignedStripeWebhook(
            $payload,
            'whsec_http_acceptance',
        ),
    )->toThrow(RuntimeException::class, 'temporärer Stripe-Ausfall');

    expect(IntegrationReceipt::query()
        ->where('provider', 'stripe:handled')
        ->where('event_id', 'evt_http_retry')
        ->value('status'))->toBe('failed')
        ->and($company->fresh()?->current_plan_id)->toBeNull();

    $gateway->available = true;
    erinPostSignedStripeWebhook($payload, 'whsec_http_acceptance')->assertOk();

    expect(IntegrationReceipt::query()
        ->where('provider', 'stripe:handled')
        ->where('event_id', 'evt_http_retry')
        ->value('status'))->toBe('processed')
        ->and($company->fresh()?->subscription_status)->toBe('active')
        ->and($company->fresh()?->current_plan_id)->toBe($plan->getKey());
});

it('rejects a webhook with an invalid Stripe signature without changing billing state', function () {
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_http_invalid',
        'stripe_price_id' => 'price_http_invalid',
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => null,
        'stripe_id' => 'cus_http_invalid',
        'status' => CompanyStatus::Pending,
        'subscription_status' => 'incomplete',
    ]);
    $payload = erinStripeSubscriptionPayload(
        'evt_http_invalid',
        'customer.subscription.created',
        $company,
        $plan,
    );

    erinPostSignedStripeWebhook($payload, 'whsec_http_acceptance', false)->assertForbidden();

    expect($company->fresh()?->status)->toBe(CompanyStatus::Pending)
        ->and($company->fresh()?->current_plan_id)->toBeNull()
        ->and($company->subscriptions()->count())->toBe(0)
        ->and(IntegrationReceipt::query()->count())->toBe(0);
});

it('revokes portal access after a signed subscription deletion webhook', function () {
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_http_deleted',
        'stripe_price_id' => 'price_http_deleted',
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => null,
        'stripe_id' => 'cus_http_deleted',
        'status' => CompanyStatus::Pending,
        'subscription_status' => 'incomplete',
    ]);
    $created = erinStripeSubscriptionPayload(
        'evt_http_before_delete',
        'customer.subscription.created',
        $company,
        $plan,
    );
    erinPostSignedStripeWebhook($created, 'whsec_http_acceptance')->assertOk();

    $deleted = erinStripeSubscriptionPayload(
        'evt_http_deleted',
        'customer.subscription.deleted',
        $company,
        $plan,
        'canceled',
    );
    erinPostSignedStripeWebhook($deleted, 'whsec_http_acceptance')->assertOk();
    $company->refresh();

    expect($company->subscription_status)->toBe('canceled')
        ->and($company->subscription('default')?->stripe_status)->toBe('canceled')
        ->and(app(EntitlementService::class)->hasPortalAccess($company))->toBeFalse()
        ->and(IntegrationReceipt::query()
            ->where('provider', 'stripe:handled')
            ->where('event_id', 'evt_http_deleted')
            ->where('status', 'processed')
            ->count())->toBe(1);
});

it('does not restore canceled access when an older subscription update arrives late', function () {
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_http_ordering',
        'stripe_price_id' => 'price_http_ordering',
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => null,
        'stripe_id' => 'cus_http_ordering',
        'status' => CompanyStatus::Pending,
        'subscription_status' => 'incomplete',
    ]);
    $baseTimestamp = now()->subMinute()->getTimestamp();

    erinPostSignedStripeWebhook(erinStripeSubscriptionPayload(
        'evt_http_ordering_created',
        'customer.subscription.created',
        $company,
        $plan,
        'active',
        $baseTimestamp,
    ), 'whsec_http_acceptance')->assertOk();

    $deleted = erinStripeSubscriptionPayload(
        'evt_http_ordering_deleted',
        'customer.subscription.deleted',
        $company,
        $plan,
        'canceled',
        $baseTimestamp + 20,
    );
    erinPostSignedStripeWebhook($deleted, 'whsec_http_acceptance')->assertOk();

    $staleUpdate = erinStripeSubscriptionPayload(
        'evt_http_ordering_stale_update',
        'customer.subscription.updated',
        $company,
        $plan,
        'active',
        $baseTimestamp + 10,
    );
    $gateway = app(StripeSubscriptionGateway::class);
    expect($gateway)->toBeInstanceOf(ErinStripeSubscriptionGateway::class);
    $gateway->put($deleted['data']['object']);
    erinPostSignedStripeWebhook($staleUpdate, 'whsec_http_acceptance')->assertOk();

    $company->refresh();

    expect($company->subscription_status)->toBe('canceled')
        ->and($company->stripe_subscription_id)->toBe('sub_http_acceptance')
        ->and($company->subscription('default')?->stripe_status)->toBe('canceled')
        ->and(app(EntitlementService::class)->hasPortalAccess($company))->toBeFalse()
        ->and(IntegrationReceipt::query()
            ->where('provider', 'stripe:handled')
            ->where('event_id', 'evt_http_ordering_stale_update')
            ->where('status', 'processed')
            ->count())->toBe(1);
});

it('uses canonical Stripe state for equal-second events and fully repairs prices quantities and items', function () {
    $oldPlan = Plan::factory()->create([
        'stripe_product_id' => 'prod_equal_second_old',
        'stripe_price_id' => 'price_equal_second_old',
    ]);
    $currentPlan = Plan::factory()->create([
        'stripe_product_id' => 'prod_equal_second_current',
        'stripe_price_id' => 'price_equal_second_current',
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => $oldPlan->getKey(),
        'stripe_id' => 'cus_equal_second',
        'stripe_subscription_id' => 'sub_equal_second',
        'status' => CompanyStatus::Active,
        'subscription_status' => 'active',
    ]);
    $subscription = $company->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_equal_second',
        'stripe_status' => 'active',
        'stripe_price' => $oldPlan->stripe_price_id,
        'quantity' => 99,
    ]);
    $subscription->items()->create([
        'stripe_id' => 'si_equal_second_current',
        'stripe_product' => $oldPlan->stripe_product_id,
        'stripe_price' => $oldPlan->stripe_price_id,
        'quantity' => 99,
    ]);
    $subscription->items()->create([
        'stripe_id' => 'si_equal_second_rogue',
        'stripe_product' => 'prod_rogue',
        'stripe_price' => 'price_rogue',
        'quantity' => 50,
    ]);
    $timestamp = now()->getTimestamp();
    $canonical = erinStripeSubscriptionPayload(
        'evt_equal_second_canonical',
        'customer.subscription.updated',
        $company,
        $currentPlan,
        'active',
        $timestamp,
        'sub_equal_second',
        $timestamp,
        [[
            'id' => 'si_equal_second_current',
            'quantity' => 3,
            'current_period_start' => now()->startOfDay()->getTimestamp(),
            'current_period_end' => now()->addMonths(4)->startOfDay()->getTimestamp(),
            'price' => [
                'id' => $currentPlan->stripe_price_id,
                'product' => $currentPlan->stripe_product_id,
            ],
        ]],
    );
    $stale = erinStripeSubscriptionPayload(
        'evt_z_equal_second',
        'customer.subscription.updated',
        $company,
        $oldPlan,
        'past_due',
        $timestamp,
        'sub_equal_second',
        $timestamp,
    );
    $gateway = app(StripeSubscriptionGateway::class);
    expect($gateway)->toBeInstanceOf(ErinStripeSubscriptionGateway::class);
    $gateway->put($canonical['data']['object']);

    erinPostSignedStripeWebhook($stale, 'whsec_http_acceptance')->assertOk();

    $secondStale = $stale;
    $secondStale['id'] = 'evt_a_equal_second';
    $secondStale['data']['object']['items']['data'][0]['quantity'] = 500;
    erinPostSignedStripeWebhook($secondStale, 'whsec_http_acceptance')->assertOk();

    $company->refresh();
    /** @var Subscription $subscription */
    $subscription = $company->subscriptions()
        ->where('stripe_id', 'sub_equal_second')
        ->sole();

    expect($company)
        ->current_plan_id->toBe($currentPlan->getKey())
        ->subscription_status->toBe('active')
        ->and($subscription)
        ->stripe_status->toBe('active')
        ->stripe_price->toBe($currentPlan->stripe_price_id)
        ->quantity->toBe(3)
        ->and($subscription->items()->count())->toBe(1)
        ->and($subscription->items()->sole())
        ->stripe_id->toBe('si_equal_second_current')
        ->stripe_product->toBe($currentPlan->stripe_product_id)
        ->stripe_price->toBe($currentPlan->stripe_price_id)
        ->quantity->toBe(3)
        ->and(IntegrationReceipt::query()
            ->where('provider', 'stripe:handled')
            ->whereIn('event_id', ['evt_z_equal_second', 'evt_a_equal_second'])
            ->count())->toBe(2);
});

it('keeps an active replacement subscription authoritative when an old event arrives late', function () {
    $oldPlan = Plan::factory()->create([
        'stripe_product_id' => 'prod_replacement_old',
        'stripe_price_id' => 'price_replacement_old',
    ]);
    $replacementPlan = Plan::factory()->create([
        'stripe_product_id' => 'prod_replacement_new',
        'stripe_price_id' => 'price_replacement_new',
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => null,
        'stripe_id' => 'cus_replacement',
        'status' => CompanyStatus::Pending,
        'subscription_status' => 'incomplete',
    ]);
    $baseTimestamp = now()->subMinute()->getTimestamp();
    $oldActive = erinStripeSubscriptionPayload(
        'evt_replacement_old_created',
        'customer.subscription.created',
        $company,
        $oldPlan,
        'active',
        $baseTimestamp,
        'sub_replacement_old',
        $baseTimestamp,
        null,
        1,
    );
    erinPostSignedStripeWebhook($oldActive, 'whsec_http_acceptance')->assertOk();

    $replacementActive = erinStripeSubscriptionPayload(
        'evt_replacement_new_created',
        'customer.subscription.created',
        $company,
        $replacementPlan,
        'active',
        $baseTimestamp + 10,
        'sub_replacement_new',
        $baseTimestamp,
        null,
        2,
    );
    erinPostSignedStripeWebhook($replacementActive, 'whsec_http_acceptance')->assertOk();

    $oldCanceled = erinStripeSubscriptionPayload(
        'evt_replacement_old_canonical_canceled',
        'customer.subscription.deleted',
        $company,
        $oldPlan,
        'canceled',
        $baseTimestamp + 20,
        'sub_replacement_old',
        $baseTimestamp,
        null,
        1,
    );
    $lateOldEvent = erinStripeSubscriptionPayload(
        'evt_replacement_old_late_update',
        'customer.subscription.updated',
        $company,
        $replacementPlan,
        'active',
        $baseTimestamp + 30,
        'sub_replacement_old',
        $baseTimestamp,
        [[
            'id' => 'si_replacement_corrupt',
            'quantity' => 999,
            'price' => [
                'id' => $replacementPlan->stripe_price_id,
                'product' => $replacementPlan->stripe_product_id,
            ],
        ]],
        1,
    );
    $gateway = app(StripeSubscriptionGateway::class);
    expect($gateway)->toBeInstanceOf(ErinStripeSubscriptionGateway::class);
    $gateway->put($oldCanceled['data']['object']);

    erinPostSignedStripeWebhook($lateOldEvent, 'whsec_http_acceptance')->assertOk();
    erinPostSignedStripeWebhook($lateOldEvent, 'whsec_http_acceptance')->assertOk();

    $company->refresh();
    /** @var Subscription $oldSubscription */
    $oldSubscription = $company->subscriptions()
        ->where('stripe_id', 'sub_replacement_old')
        ->sole();
    /** @var Subscription $replacementSubscription */
    $replacementSubscription = $company->subscriptions()
        ->where('stripe_id', 'sub_replacement_new')
        ->sole();

    expect($company)
        ->stripe_subscription_id->toBe('sub_replacement_new')
        ->stripe_subscription_generation->toBe(2)
        ->current_plan_id->toBe($replacementPlan->getKey())
        ->subscription_status->toBe('active')
        ->and($replacementSubscription->stripe_status)->toBe('active')
        ->and($oldSubscription)
        ->stripe_status->toBe('canceled')
        ->stripe_price->toBe($oldPlan->stripe_price_id)
        ->quantity->toBe(1)
        ->and($oldSubscription->items()->count())->toBe(1)
        ->and($oldSubscription->items()->sole()->stripe_price)->toBe($oldPlan->stripe_price_id)
        ->and($oldSubscription->items()->where('stripe_id', 'si_replacement_corrupt')->exists())
        ->toBeFalse()
        ->and(app(EntitlementService::class)->hasPortalAccess($company))->toBeTrue()
        ->and(IntegrationReceipt::query()
            ->where('provider', 'stripe:handled')
            ->where('event_id', 'evt_replacement_old_late_update')
            ->where('status', 'processed')
            ->count())->toBe(1);
});
