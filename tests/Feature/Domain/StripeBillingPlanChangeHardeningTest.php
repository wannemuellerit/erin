<?php

use App\Contracts\StripeBillingChangeGateway;
use App\Contracts\StripeSubscriptionGateway;
use App\Enums\CompanyStatus;
use App\Models\BillingChangeIntent;
use App\Models\Company;
use App\Models\Plan;
use App\Models\StripeAddonPrice;
use App\Services\Billing\BillingPlanChangeManager;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\PlanStripePriceRegistry;
use App\Services\Billing\StripeAddonPriceRegistry;
use App\Services\Billing\StripeSubscriptionSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Stripe\Price;
use Stripe\StripeObject;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;
use Tests\Support\ErinStripeBillingChangeGateway;
use Tests\Support\ErinStripeSubscriptionGateway;

uses(RefreshDatabase::class);

/**
 * @return array{0: Company, 1: Plan, 2: Plan}
 */
function erinHardeningCompanyAndPlans(
    bool $upgrade = true,
): array {
    $current = Plan::factory()->create([
        'name' => 'Hardening Business',
        'price_cents' => 349_900,
        'term_months' => 4,
        'stripe_product_id' => 'prod_hardening_business',
        'stripe_price_id' => 'price_hardening_business',
    ]);
    $target = Plan::factory()->create([
        'name' => $upgrade ? 'Hardening Premium' : 'Hardening Basic',
        'price_cents' => $upgrade ? 499_900 : 299_900,
        'term_months' => $upgrade ? 6 : 2,
        'stripe_product_id' => $upgrade
            ? 'prod_hardening_premium'
            : 'prod_hardening_basic',
        'stripe_price_id' => $upgrade
            ? 'price_hardening_premium'
            : 'price_hardening_basic',
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => $current->getKey(),
        'stripe_id' => 'cus_hardening_company',
        'stripe_subscription_id' => 'sub_hardening_company',
        'status' => CompanyStatus::Active,
        'subscription_status' => 'active',
    ]);

    return [$company, $current, $target];
}

/**
 * @param  array<string, mixed>  $extra
 */
function erinHardeningSubscription(
    Company $company,
    Plan $plan,
    array $extra = [],
): Subscription {
    $start = now()->startOfDay()->getTimestamp();
    $end = now()->startOfDay()->addMonths(4)->getTimestamp();

    return Subscription::constructFrom([
        'id' => $company->stripe_subscription_id,
        'object' => 'subscription',
        'customer' => $company->stripe_id,
        'status' => 'active',
        'schedule' => null,
        'items' => [
            'object' => 'list',
            'data' => [[
                'id' => 'si_hardening_base',
                'object' => 'subscription_item',
                'quantity' => 1,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'price' => [
                    'id' => $plan->stripe_price_id,
                    'object' => 'price',
                    'product' => $plan->stripe_product_id,
                ],
            ]],
        ],
        ...$extra,
    ]);
}

function erinHardeningSchedule(
    Company $company,
    Plan $plan,
    string $endBehavior = 'release',
): SubscriptionSchedule {
    $start = now()->startOfDay()->getTimestamp();
    $end = now()->startOfDay()->addMonths(4)->getTimestamp();

    return SubscriptionSchedule::constructFrom([
        'id' => 'sub_sched_hardening',
        'object' => 'subscription_schedule',
        'customer' => $company->stripe_id,
        'subscription' => $company->stripe_subscription_id,
        'status' => 'active',
        'end_behavior' => $endBehavior,
        'current_phase' => [
            'start_date' => $start,
            'end_date' => $end,
        ],
        'phases' => [[
            'start_date' => $start,
            'end_date' => $end,
            'metadata' => ['source' => 'original'],
            'items' => [[
                'price' => $plan->stripe_price_id,
                'quantity' => 1,
            ]],
            'proration_behavior' => 'none',
        ]],
    ]);
}

beforeEach(function () {
    config()->set('services.stripe.seat_price_id');
    config()->set('services.stripe.seat_product_id');
    config()->set('services.stripe.visa_price_id');
    config()->set('services.stripe.visa_product_id');
});

it('moves a remotely released schedule after a post-commit crash into terminal manual review', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans(false);
    $subscription = erinHardeningSubscription($company, $current, [
        'schedule' => 'sub_sched_hardening',
    ]);
    $gateway = new ErinStripeBillingChangeGateway(
        $subscription,
        erinHardeningSchedule($company, $current),
    );
    $gateway->afterScheduleUpdate = function () use ($gateway): never {
        $gateway->schedule->status = 'released';
        $gateway->schedule->subscription = null;
        $gateway->schedule->released_subscription
            = $gateway->subscription->id;
        $gateway->subscription->schedule = null;

        throw new RuntimeException(
            'Simulierter Prozessabbruch nach externer Schedule-Freigabe.',
        );
    };
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );
    expect($intent->status)->toBe('reconcile')
        ->and($gateway->scheduleMutations)->toBe(1);

    $review = app(BillingPlanChangeManager::class)->reconcile($intent);
    $attempts = $review->attempts;

    expect($review->status)->toBe('manual_review')
        ->and($review->active_company_key)->not->toBeNull()
        ->and($review->last_error)->toContain('manuell geprüft')
        ->and($gateway->updateScheduleCalls)->toHaveCount(1)
        ->and($company->fresh()?->current_plan_id)->toBe(
            $current->getKey(),
        )
        ->and($company->fresh()?->pending_plan_id)->toBeNull()
        ->and(Artisan::call('erin:stripe:reconcile-billing'))
        ->toBe(1)
        ->and($review->refresh()->attempts)->toBe($attempts)
        ->and(Artisan::output())->toContain(
            '1 benötigen eine manuelle Prüfung',
        );
});

it('never bypasses a persisted upgrade schedule when the target price is already active', function (
    string $drift,
) {
    [$company, $current, $target] = erinHardeningCompanyAndPlans();
    $external = Plan::factory()->create([
        'name' => 'Extern veränderter Folgetarif',
        'price_cents' => 429_900,
        'term_months' => 4,
        'stripe_product_id' => 'prod_hardening_upgrade_external',
        'stripe_price_id' => 'price_hardening_upgrade_external',
    ]);
    app(PlanStripePriceRegistry::class)->record(
        $external,
        'upgrade_external_schedule_test',
    );
    $subscription = erinHardeningSubscription($company, $current, [
        'schedule' => 'sub_sched_hardening',
    ]);
    $schedule = erinHardeningSchedule($company, $current);
    $phases = $schedule->toArray()['phases'];
    $futureStart = $phases[0]['end_date'];
    $phases[] = [
        'start_date' => $futureStart,
        'end_date' => now()->startOfDay()->addMonths(8)->getTimestamp(),
        'metadata' => ['source' => 'planned'],
        'items' => [[
            'price' => $current->stripe_price_id,
            'quantity' => 1,
        ]],
        'proration_behavior' => 'none',
    ];
    $schedule->phases = $phases;
    $gateway = new ErinStripeBillingChangeGateway(
        $subscription,
        $schedule,
    );
    $gateway->afterScheduleUpdate = function () use (
        $gateway,
        $external,
        $drift,
    ): never {
        if ($drift === 'released') {
            $gateway->schedule->status = 'released';
            $gateway->schedule->subscription = null;
            $gateway->subscription->schedule = null;
        } elseif ($drift === 'replaced') {
            $gateway->subscription->schedule
                = 'sub_sched_external_replacement';
        } else {
            $phases = $gateway->schedule->toArray()['phases'];
            $phases[1]['items'][0]['price']
                = $external->stripe_price_id;
            $gateway->schedule->phases = $phases;
        }

        throw new RuntimeException(
            'Simulierter Abschluss-Crash nach externer Upgrade-Schedule-Änderung.',
        );
    };
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );
    expect($intent->status)->toBe('reconcile')
        ->and($gateway->subscription->items->data[0]->price->id)
        ->toBe($target->stripe_price_id);

    $review = app(BillingPlanChangeManager::class)->reconcile($intent);

    expect($review->status)->toBe('manual_review')
        ->and($review->active_company_key)->not->toBeNull()
        ->and($gateway->updateScheduleCalls)->toHaveCount(1)
        ->and($company->fresh()?->current_plan_id)->toBe(
            $current->getKey(),
        )
        ->and($company->fresh()?->pending_plan_id)->toBeNull();
})->with([
    'Schedule wurde freigegeben' => ['released'],
    'Schedule wurde ersetzt' => ['replaced'],
    'Zukunftsphase wurde verändert' => ['future_phase'],
]);

it('does not apply a schedule whose canonical future phase changed after remote success', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans(false);
    $external = Plan::factory()->create([
        'name' => 'Extern geplanter Tarif',
        'price_cents' => 319_900,
        'term_months' => 3,
        'stripe_product_id' => 'prod_hardening_external',
        'stripe_price_id' => 'price_hardening_external',
    ]);
    app(PlanStripePriceRegistry::class)->record(
        $external,
        'external_schedule_test',
    );
    $subscription = erinHardeningSubscription($company, $current, [
        'schedule' => 'sub_sched_hardening',
    ]);
    $gateway = new ErinStripeBillingChangeGateway(
        $subscription,
        erinHardeningSchedule($company, $current),
    );
    $gateway->afterScheduleUpdate = function () use (
        $gateway,
        $external,
    ): never {
        $phases = $gateway->schedule->toArray()['phases'];
        $phases[1]['items'][0]['price'] = $external->stripe_price_id;
        $gateway->schedule->phases = $phases;

        throw new RuntimeException(
            'Simulierter Prozessabbruch nach externer Zukunftsphase.',
        );
    };
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );
    expect($intent->status)->toBe('reconcile')
        ->and(data_get(
            $gateway->schedule->toArray(),
            'phases.1.items.0.price',
        ))->toBe($external->stripe_price_id);
    $review = app(BillingPlanChangeManager::class)->reconcile($intent);

    expect($review->status)->toBe('manual_review')
        ->and($gateway->scheduleMutations)->toBe(1)
        ->and($company->fresh()?->current_plan_id)->toBe(
            $current->getKey(),
        )
        ->and($company->fresh()?->pending_plan_id)->toBeNull();
});

it('keeps idempotent fake responses immutable when live provider state changes later', function () {
    [$company, $current] = erinHardeningCompanyAndPlans(false);
    $subscription = erinHardeningSubscription($company, $current, [
        'schedule' => 'sub_sched_hardening',
    ]);
    $schedule = erinHardeningSchedule($company, $current);
    $gateway = new ErinStripeBillingChangeGateway(
        $subscription,
        $schedule,
    );
    $parameters = [
        'end_behavior' => 'release',
        'phases' => $schedule->toArray()['phases'],
        'proration_behavior' => 'none',
    ];

    $first = $gateway->updateSchedule(
        $schedule->id,
        $parameters,
        'immutable-response-key',
    );
    $gateway->schedule->status = 'released';
    $gateway->schedule->subscription = null;
    $gateway->schedule->released_subscription = $subscription->id;
    $gateway->schedule->phases = [[
        ...$parameters['phases'][0],
        'metadata' => ['external' => 'mutated'],
    ]];
    $second = $gateway->updateSchedule(
        $schedule->id,
        $parameters,
        'immutable-response-key',
    );

    expect($first->status)->toBe('active')
        ->and($second->status)->toBe('active')
        ->and(data_get($second->toArray(), 'phases.0.metadata.external'))
        ->toBeNull()
        ->and($gateway->schedule->status)->toBe('released');
});

it('recovers a transient failed schedule update through the scheduler with the same payload', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans(false);
    $subscription = erinHardeningSubscription($company, $current, [
        'schedule' => 'sub_sched_hardening',
    ]);
    $gateway = new ErinStripeBillingChangeGateway(
        $subscription,
        erinHardeningSchedule($company, $current),
    );
    $gateway->scheduleFailuresRemaining = 1;
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );
    expect($intent->status)->toBe('reconcile')
        ->and($gateway->updateScheduleCalls)->toHaveCount(1);

    expect(Artisan::call('erin:stripe:reconcile-billing'))->toBe(0)
        ->and($intent->refresh()->status)->toBe('applied')
        ->and($gateway->updateScheduleCalls)->toHaveCount(2)
        ->and($gateway->updateScheduleCalls[0]['parameters'])
        ->toBe($gateway->updateScheduleCalls[1]['parameters'])
        ->and($gateway->updateScheduleCalls[0]['idempotency_key'])
        ->toBe($gateway->updateScheduleCalls[1]['idempotency_key'])
        ->and($company->fresh()?->pending_plan_id)->toBe(
            $target->getKey(),
        );
});

it('uses allow incomplete for immediate proration and keeps access after a failed upgrade invoice', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans();
    $gateway = new ErinStripeBillingChangeGateway(
        erinHardeningSubscription($company, $current),
    );
    $gateway->invoicePaymentFails = true;
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );

    expect($intent->status)->toBe('applied')
        ->and($gateway->subscription->status)->toBe('past_due')
        ->and($gateway->subscriptionMutations)->toBe(1)
        ->and($gateway->updateSubscriptionCalls)->toHaveCount(1);
    $parameters = $gateway->updateSubscriptionCalls[0]['parameters'];
    expect($parameters['payment_behavior'])->toBe('allow_incomplete')
        ->and($parameters['proration_behavior'])->toBe('always_invoice')
        ->and($parameters)->not->toHaveKey('cancel_at_period_end')
        ->and($company->fresh()?->status)->toBe(CompanyStatus::Active);
});

it('blocks a credit-risking proration on an unpaid period without blocking portal access', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans();
    $subscription = erinHardeningSubscription($company, $current, [
        'status' => 'past_due',
        'latest_invoice' => [
            'id' => 'in_hardening_unpaid',
            'object' => 'invoice',
            'status' => 'open',
            'amount_remaining' => 349_900,
        ],
    ]);
    $gateway = new ErinStripeBillingChangeGateway($subscription);
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );

    expect($intent->status)->toBe('reconcile')
        ->and($gateway->updateSubscriptionCalls)->toBeEmpty()
        ->and($gateway->subscriptionMutations)->toBe(0)
        ->and($company->fresh()?->status)->toBe(CompanyStatus::Active)
        ->and($company->fresh()?->current_plan_id)->toBe(
            $current->getKey(),
        );
});

it('never marks a provider pending update as applied', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans();
    $gateway = new ErinStripeBillingChangeGateway(
        erinHardeningSubscription($company, $current),
    );
    $gateway->returnPendingUpdate = true;
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );
    $retried = app(BillingPlanChangeManager::class)->reconcile($intent);

    expect($intent->status)->toBe('reconcile')
        ->and($retried->status)->toBe('reconcile')
        ->and($gateway->updateSubscriptionCalls)->toHaveCount(1)
        ->and($gateway->subscriptionMutations)->toBe(0)
        ->and($company->fresh()?->pending_plan_id)->toBeNull()
        ->and($gateway->subscription->items->data[0]->price->id)
        ->toBe('price_hardening_business');
});

it('recovers after remote upgrade success using the immutable intent price despite catalog rotation', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans();
    $gateway = new ErinStripeBillingChangeGateway(
        erinHardeningSubscription($company, $current),
    );
    $gateway->afterSubscriptionUpdate = function (): never {
        throw new RuntimeException(
            'Simulierter Prozessabbruch nach Stripe-Erfolg.',
        );
    };
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );
    expect($intent->status)->toBe('reconcile')
        ->and($gateway->subscriptionMutations)->toBe(1)
        ->and(data_get(
            $intent->context,
            'remote_operations.subscription_upgrade.payload.items.0.price',
        ))->toBe('price_hardening_premium');

    $target->forceFill([
        'stripe_product_id' => 'prod_hardening_premium_rotated',
        'stripe_price_id' => 'price_hardening_premium_rotated',
    ])->save();
    $reconciled = app(BillingPlanChangeManager::class)->reconcile($intent);

    expect($reconciled->status)->toBe('applied')
        ->and($gateway->updateSubscriptionCalls)->toHaveCount(1)
        ->and($gateway->subscriptionMutations)->toBe(1)
        ->and($gateway->subscription->items->data[0]->price->id)
        ->toBe('price_hardening_premium');
});

it('replays the exact persisted schedule payload after a post-commit crash', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans(false);
    $subscription = erinHardeningSubscription($company, $current, [
        'schedule' => 'sub_sched_hardening',
    ]);
    $schedule = erinHardeningSchedule($company, $current, 'release');
    $gateway = new ErinStripeBillingChangeGateway(
        $subscription,
        $schedule,
    );
    $gateway->afterScheduleUpdate = function (): never {
        throw new RuntimeException(
            'Simulierter Prozessabbruch nach Schedule-Commit.',
        );
    };
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );
    expect($intent->status)->toBe('reconcile')
        ->and($gateway->scheduleMutations)->toBe(1)
        ->and($gateway->updateScheduleCalls)->toHaveCount(1);

    $reconciled = app(BillingPlanChangeManager::class)->reconcile($intent);

    expect($reconciled->status)->toBe('applied')
        ->and($gateway->updateScheduleCalls)->toHaveCount(2)
        ->and($gateway->updateScheduleCalls[0]['parameters'])
        ->toBe($gateway->updateScheduleCalls[1]['parameters'])
        ->and($gateway->updateScheduleCalls[0]['idempotency_key'])
        ->toBe($gateway->updateScheduleCalls[1]['idempotency_key'])
        ->and($gateway->scheduleMutations)->toBe(1);
});

it('rejects a different payload reused with the same provider idempotency key', function () {
    [$company, $current] = erinHardeningCompanyAndPlans(false);
    $subscription = erinHardeningSubscription($company, $current, [
        'schedule' => 'sub_sched_hardening',
    ]);
    $schedule = erinHardeningSchedule($company, $current);
    $gateway = new ErinStripeBillingChangeGateway(
        $subscription,
        $schedule,
    );
    $parameters = [
        'end_behavior' => 'release',
        'phases' => $schedule->toArray()['phases'],
        'proration_behavior' => 'none',
    ];
    $gateway->updateSchedule(
        $schedule->id,
        $parameters,
        'hardening-idempotency',
    );

    $parameters['end_behavior'] = 'cancel';
    expect(fn () => $gateway->updateSchedule(
        $schedule->id,
        $parameters,
        'hardening-idempotency',
    ))->toThrow(
        RuntimeException::class,
        'abweichendem Payload',
    );
});

it('finalizes a downgrade already transitioned remotely after the local process crashed', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans(false);
    $subscription = erinHardeningSubscription($company, $current, [
        'schedule' => 'sub_sched_hardening',
    ]);
    $schedule = erinHardeningSchedule($company, $current);
    $gateway = new ErinStripeBillingChangeGateway(
        $subscription,
        $schedule,
    );
    $gateway->afterScheduleUpdate = function (): never {
        throw new RuntimeException('Simulierter Abschluss-Crash.');
    };
    app()->instance(StripeBillingChangeGateway::class, $gateway);
    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );
    expect($intent->status)->toBe('reconcile');

    $gateway->subscription->items->data[0]->price = Price::constructFrom([
        'id' => $target->stripe_price_id,
        'object' => 'price',
        'product' => $target->stripe_product_id,
    ]);
    $transitionedPhase = $gateway->schedule->toArray()['phases'][1];
    $gateway->schedule->current_phase = StripeObject::constructFrom([
        'start_date' => $transitionedPhase['start_date'],
        'end_date' => $transitionedPhase['end_date'],
    ]);
    $reconciled = app(BillingPlanChangeManager::class)->reconcile($intent);

    expect($reconciled->status)->toBe('applied')
        ->and($gateway->updateScheduleCalls)->toHaveCount(1)
        ->and($company->fresh()?->current_plan_id)->toBe($target->getKey())
        ->and($company->fresh()?->pending_plan_id)->toBeNull();
});

it('moves a transitioned downgrade with a drifted persisted target phase into manual review', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans(false);
    $gateway = new ErinStripeBillingChangeGateway(
        erinHardeningSubscription($company, $current, [
            'schedule' => 'sub_sched_hardening',
        ]),
        erinHardeningSchedule($company, $current),
    );
    $gateway->afterScheduleUpdate = function (): never {
        throw new RuntimeException('Simulierter Abschluss-Crash.');
    };
    app()->instance(StripeBillingChangeGateway::class, $gateway);
    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );
    expect($intent->status)->toBe('reconcile');

    $gateway->subscription->items->data[0]->price = Price::constructFrom([
        'id' => $target->stripe_price_id,
        'object' => 'price',
        'product' => $target->stripe_product_id,
    ]);
    $phases = $gateway->schedule->toArray()['phases'];
    $phases[1]['metadata']['external_change'] = 'after_commit';
    $gateway->schedule->phases = $phases;
    $gateway->schedule->current_phase = StripeObject::constructFrom([
        'start_date' => $phases[1]['start_date'],
        'end_date' => $phases[1]['end_date'],
    ]);

    $review = app(BillingPlanChangeManager::class)->reconcile($intent);

    expect($review->status)->toBe('manual_review')
        ->and($review->active_company_key)->not->toBeNull()
        ->and($gateway->updateScheduleCalls)->toHaveCount(1)
        ->and($company->fresh()?->current_plan_id)->toBe(
            $current->getKey(),
        )
        ->and($company->fresh()?->pending_plan_id)->toBeNull();
});

it('blocks base and add-on price role collisions in both registration orders', function () {
    $base = Plan::factory()->create([
        'stripe_product_id' => 'prod_role_base',
        'stripe_price_id' => 'price_role_collision',
    ]);
    app(PlanStripePriceRegistry::class)->record($base, 'role_test');
    expect(fn () => app(StripeAddonPriceRegistry::class)->record(
        'role_addon',
        'price_role_collision',
        'prod_role_addon',
    ))->toThrow(LogicException::class, 'zugleich');

    app(StripeAddonPriceRegistry::class)->record(
        'role_addon_first',
        'price_role_addon_first',
        'prod_role_addon_first',
    );
    $secondBase = Plan::factory()->create([
        'stripe_product_id' => 'prod_role_second_base',
        'stripe_price_id' => 'price_role_addon_first',
    ]);
    expect(fn () => app(PlanStripePriceRegistry::class)->record(
        $secondBase,
        'role_test',
    ))->toThrow(LogicException::class, 'zugleich');
});

it('synchronizes seat and visa rotations while blocking retired historical base prices', function () {
    config()->set('services.stripe.seat_price_id', 'price_role_seat_v1');
    config()->set('services.stripe.seat_product_id', 'prod_role_seat');
    config()->set('services.stripe.visa_price_id', 'price_role_visa_v1');
    config()->set('services.stripe.visa_product_id', 'prod_role_visa');
    $registry = app(StripeAddonPriceRegistry::class);
    $registry->synchronizeConfiguredAddOns();

    config()->set('services.stripe.seat_price_id', 'price_role_seat_v2');
    config()->set('services.stripe.visa_price_id', 'price_role_visa_v2');
    $registry->synchronizeConfiguredAddOns();

    expect(StripeAddonPrice::query()
        ->where('code', 'recruiter_seat')
        ->where('stripe_price_id', 'price_role_seat_v1')
        ->whereNotNull('retired_at')
        ->exists())->toBeTrue()
        ->and(StripeAddonPrice::query()
            ->where('code', 'recruiter_seat')
            ->where('stripe_price_id', 'price_role_seat_v2')
            ->whereNull('retired_at')
            ->exists())->toBeTrue()
        ->and(StripeAddonPrice::query()
            ->where('code', 'visa_package')
            ->where('stripe_price_id', 'price_role_visa_v1')
            ->whereNotNull('retired_at')
            ->exists())->toBeTrue()
        ->and(StripeAddonPrice::query()
            ->where('code', 'visa_package')
            ->where('stripe_price_id', 'price_role_visa_v2')
            ->whereNull('retired_at')
            ->exists())->toBeTrue();

    $base = Plan::factory()->create([
        'stripe_product_id' => 'prod_role_historical_base',
        'stripe_price_id' => 'price_role_historical_base',
    ]);
    $basePrices = app(PlanStripePriceRegistry::class);
    $basePrices->record($base, 'historical_collision');
    $base->forceFill([
        'stripe_price_id' => 'price_role_historical_base_v2',
    ])->save();
    $basePrices->record($base->refresh(), 'historical_rotation');

    config()->set(
        'services.stripe.visa_price_id',
        'price_role_historical_base',
    );
    expect(fn () => $registry->synchronizeConfiguredVisaPackage())
        ->toThrow(LogicException::class, 'zugleich');

    config()->set(
        'services.stripe.visa_price_id',
        'price_role_current_base_config',
    );
    $configuredCollision = Plan::factory()->create([
        'stripe_product_id' => 'prod_role_current_base_config',
        'stripe_price_id' => 'price_role_current_base_config',
    ]);
    expect(fn () => $basePrices->record(
        $configuredCollision,
        'configured_collision',
    ))->toThrow(LogicException::class, 'zugleich');
});

it('derives future plan state from the canonical schedule and clears it after external release', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans(false);
    $start = now()->startOfDay()->getTimestamp();
    $end = now()->startOfDay()->addMonths(4)->getTimestamp();
    $futureEnd = now()->startOfDay()->addMonths(6)->getTimestamp();
    $snapshot = [
        'id' => $company->stripe_subscription_id,
        'object' => 'subscription',
        'customer' => $company->stripe_id,
        'created' => $start,
        'status' => 'active',
        'metadata' => [
            'type' => 'default',
            'company_id' => (string) $company->getKey(),
        ],
        'items' => ['data' => [[
            'id' => 'si_hardening_sync_base',
            'quantity' => 1,
            'current_period_start' => $start,
            'current_period_end' => $end,
            'price' => [
                'id' => $current->stripe_price_id,
                'product' => $current->stripe_product_id,
            ],
        ]]],
        'schedule' => [
            'id' => 'sub_sched_hardening_sync',
            'status' => 'active',
            'end_behavior' => 'release',
            'current_phase' => [
                'start_date' => $start,
                'end_date' => $end,
            ],
            'phases' => [
                [
                    'start_date' => $start,
                    'end_date' => $end,
                    'items' => [[
                        'price' => $current->stripe_price_id,
                        'quantity' => 1,
                    ]],
                ],
                [
                    'start_date' => $end,
                    'end_date' => $futureEnd,
                    'items' => [[
                        'price' => $target->stripe_price_id,
                        'quantity' => 1,
                    ]],
                ],
            ],
        ],
    ];
    $gateway = new ErinStripeSubscriptionGateway;
    $gateway->put($snapshot);
    app()->instance(StripeSubscriptionGateway::class, $gateway);

    app(StripeSubscriptionSynchronizer::class)->synchronize([
        'id' => 'evt_hardening_schedule_sync',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => $snapshot],
    ]);
    expect($company->fresh()?->pending_plan_id)->toBe($target->getKey())
        ->and($company->fresh()?->pending_plan_effective_at?->getTimestamp())
        ->toBe($end);

    $released = [...$snapshot, 'schedule' => null];
    $gateway->put($released);
    app(StripeSubscriptionSynchronizer::class)->synchronizeSchedule([
        'id' => 'evt_hardening_schedule_released',
        'type' => 'subscription_schedule.released',
        'data' => ['object' => [
            'id' => 'sub_sched_hardening_sync',
            'customer' => $company->stripe_id,
            'subscription' => null,
            'released_subscription' => $company->stripe_subscription_id,
        ]],
    ]);

    expect($company->fresh()?->pending_plan_id)->toBeNull()
        ->and($company->fresh()?->pending_plan_effective_at)->toBeNull();
});

it('pins the intent source price to the canonical historical subscription item after catalog rotation', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans();
    $registry = app(PlanStripePriceRegistry::class);
    $registry->record($current, 'historical_source');
    $subscription = erinHardeningSubscription($company, $current);

    $current->forceFill([
        'stripe_price_id' => 'price_hardening_business_v2',
    ])->save();
    $registry->record($current->refresh(), 'catalog_rotation');
    $gateway = new ErinStripeBillingChangeGateway($subscription);
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current->refresh(),
        $target,
        null,
        now(),
    );

    expect($intent->status)->toBe('applied')
        ->and($intent->from_stripe_price_id)
        ->toBe('price_hardening_business')
        ->and(data_get(
            $intent->context,
            'source_subscription_item.price',
        ))->toBe('price_hardening_business')
        ->and(data_get(
            $intent->context,
            'remote_operations.subscription_upgrade.payload.items.0.price',
        ))->toBe($target->stripe_price_id)
        ->and($company->fresh()?->current_plan_id)->toBe(
            $target->getKey(),
        );
});

it('fails closed when the canonical source item races between intent read and reconcile', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans();
    $gateway = new ErinStripeBillingChangeGateway(
        erinHardeningSubscription($company, $current),
    );
    $gateway->beforeRetrieveSubscription = function (
        ErinStripeBillingChangeGateway $fake,
        int $call,
    ): void {
        if ($call === 2) {
            $racedItem = $fake->subscription->items->data[0]->toArray();
            $racedItem['id'] = 'si_hardening_raced';

            $fake->subscription->items->data[0]
                = StripeObject::constructFrom($racedItem);
        }
    };
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );

    $attempts = $intent->attempts;

    expect($intent->status)->toBe('manual_review')
        ->and($intent->active_company_key)->not->toBeNull()
        ->and($gateway->updateSubscriptionCalls)->toBeEmpty()
        ->and($gateway->subscriptionMutations)->toBe(0)
        ->and($company->fresh()?->current_plan_id)->toBe(
            $current->getKey(),
        )
        ->and(Artisan::call('erin:stripe:reconcile-billing'))->toBe(1)
        ->and($intent->refresh()->attempts)->toBe($attempts);
});

it('rejects a target price early return when a pending update appears in parallel', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans();
    $gateway = new ErinStripeBillingChangeGateway(
        erinHardeningSubscription($company, $current),
    );
    $gateway->beforeRetrieveSubscription = function (
        ErinStripeBillingChangeGateway $fake,
        int $call,
    ) use ($target): void {
        if ($call !== 2) {
            return;
        }
        $fake->subscription->items->data[0]->price
            = Price::constructFrom([
                'id' => $target->stripe_price_id,
                'object' => 'price',
                'product' => $target->stripe_product_id,
            ]);
        $fake->subscription->pending_update = StripeObject::constructFrom([
            'subscription_items' => [[
                'id' => 'si_hardening_base',
                'price' => $target->stripe_price_id,
                'quantity' => 1,
            ]],
            'expires_at' => now()->addHour()->getTimestamp(),
        ]);
    };
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );

    expect($intent->status)->toBe('reconcile')
        ->and($gateway->updateSubscriptionCalls)->toBeEmpty()
        ->and($company->fresh()?->current_plan_id)->toBe(
            $current->getKey(),
        )
        ->and($company->fresh()?->pending_plan_id)->toBeNull();
});

it('atomically applies a canonical upgrade that already succeeded when its webhook was lost', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans();
    $gateway = new ErinStripeBillingChangeGateway(
        erinHardeningSubscription($company, $target),
    );
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );

    expect($intent->status)->toBe('applied')
        ->and($intent->from_stripe_price_id)->toBe(
            $target->stripe_price_id,
        )
        ->and($gateway->updateSubscriptionCalls)->toBeEmpty()
        ->and($company->fresh()?->current_plan_id)->toBe(
            $target->getKey(),
        )
        ->and($company->fresh()?->pending_plan_id)->toBeNull()
        ->and(app(EntitlementService::class)
            ->summary($company->fresh())['plan']['id'])
        ->toBe($target->getKey());
});

it('rejects downgrades before creating an intent when a cancellation boundary exists', function (
    bool $scheduleCancellation,
) {
    [$company, $current, $target] = erinHardeningCompanyAndPlans(false);
    $subscription = erinHardeningSubscription($company, $current, [
        'cancel_at_period_end' => ! $scheduleCancellation,
        'cancel_at' => $scheduleCancellation
            ? null
            : now()->addMonth()->getTimestamp(),
        'schedule' => $scheduleCancellation
            ? 'sub_sched_hardening'
            : null,
    ]);
    $schedule = $scheduleCancellation
        ? erinHardeningSchedule($company, $current, 'cancel')
        : null;
    $gateway = new ErinStripeBillingChangeGateway(
        $subscription,
        $schedule,
    );
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    expect(fn () => app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    ))->toThrow(DomainException::class, 'Downgrade wurde abgelehnt')
        ->and(BillingChangeIntent::query()->count())->toBe(0)
        ->and($gateway->createScheduleCalls)->toBeEmpty()
        ->and($gateway->updateScheduleCalls)->toBeEmpty();
})->with([
    'subscription cancellation timestamp' => false,
    'schedule end behavior cancel' => true,
]);

it('rejects missing or foreign schedule bindings before creating an intent', function (
    Closure $mutate,
) {
    [$company, $current, $target] = erinHardeningCompanyAndPlans();
    $subscription = erinHardeningSubscription($company, $current, [
        'schedule' => 'sub_sched_hardening',
    ]);
    $schedule = erinHardeningSchedule($company, $current);
    $mutate($schedule);
    $gateway = new ErinStripeBillingChangeGateway(
        $subscription,
        $schedule,
    );
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    expect(fn () => app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    ))->toThrow(DomainException::class, 'nicht eindeutig')
        ->and(BillingChangeIntent::query()->count())->toBe(0)
        ->and($gateway->updateScheduleCalls)->toBeEmpty()
        ->and($gateway->updateSubscriptionCalls)->toBeEmpty();
})->with([
    'missing subscription binding' => [
        function (SubscriptionSchedule $schedule): void {
            $schedule->subscription = null;
        },
    ],
    'foreign customer binding' => [
        function (SubscriptionSchedule $schedule): void {
            $schedule->customer = 'cus_foreign_hardening';
        },
    ],
    'released status' => [
        function (SubscriptionSchedule $schedule): void {
            $schedule->status = 'released';
        },
    ],
]);

it('inherits recurring phase settings and every add-on quantity into an appended downgrade phase', function () {
    [$company, $current, $target] = erinHardeningCompanyAndPlans(false);
    config()->set(
        'services.stripe.seat_price_id',
        'price_hardening_seat',
    );
    config()->set(
        'services.stripe.seat_product_id',
        'prod_hardening_seat',
    );
    app(StripeAddonPriceRegistry::class)
        ->synchronizeConfiguredRecruiterSeat();
    $subscription = erinHardeningSubscription($company, $current, [
        'schedule' => 'sub_sched_hardening',
    ]);
    $subscription->items->data[] = StripeObject::constructFrom([
        'id' => 'si_hardening_seat',
        'quantity' => 3,
        'current_period_start' => $subscription
            ->items->data[0]->current_period_start,
        'current_period_end' => $subscription
            ->items->data[0]->current_period_end,
        'price' => [
            'id' => 'price_hardening_seat',
            'object' => 'price',
            'product' => 'prod_hardening_seat',
        ],
    ]);
    $schedule = erinHardeningSchedule($company, $current);
    $snapshot = $schedule->toArray();
    $snapshot['default_settings'] = [
        'collection_method' => 'charge_automatically',
        'invoice_settings' => [
            'issuer' => ['type' => 'self'],
        ],
    ];
    $snapshot['phases'][0] = [
        ...$snapshot['phases'][0],
        'add_invoice_items' => [[
            'price' => 'price_one_time_only',
            'quantity' => 1,
        ]],
        'automatic_tax' => ['enabled' => true],
        'billing_cycle_anchor' => 'phase_start',
        'collection_method' => 'charge_automatically',
        'default_tax_rates' => ['txr_hardening'],
        'discounts' => [['coupon' => 'coupon_hardening']],
        'invoice_settings' => [
            'issuer' => ['type' => 'self'],
        ],
        'metadata' => ['policy' => 'carry-forward'],
        'payment_settings' => [
            'save_default_payment_method' => 'on_subscription',
        ],
        'trial_end' => now()->addDay()->getTimestamp(),
        'items' => [
            [
                'price' => $current->stripe_price_id,
                'quantity' => 1,
            ],
            [
                'price' => 'price_hardening_seat',
                'quantity' => 3,
            ],
        ],
    ];
    $schedule = SubscriptionSchedule::constructFrom($snapshot);
    $gateway = new ErinStripeBillingChangeGateway(
        $subscription,
        $schedule,
    );
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );
    $parameters = $gateway->updateScheduleCalls[0]['parameters'];
    $future = $parameters['phases'][1];

    expect($intent->status)->toBe('applied')
        ->and($parameters['default_settings'])
        ->toBe($snapshot['default_settings'])
        ->and($future['automatic_tax'])->toBe(['enabled' => true])
        ->and($future['billing_cycle_anchor'])->toBe('phase_start')
        ->and($future['collection_method'])
        ->toBe('charge_automatically')
        ->and($future['default_tax_rates'])
        ->toBe(['txr_hardening'])
        ->and($future['discounts'])
        ->toBe([['coupon' => 'coupon_hardening']])
        ->and($future['invoice_settings'])
        ->toBe(['issuer' => ['type' => 'self']])
        ->and($future['metadata'])
        ->toBe(['policy' => 'carry-forward'])
        ->and($future['payment_settings'])
        ->toBe(['save_default_payment_method' => 'on_subscription'])
        ->and($future)->not->toHaveKey('add_invoice_items')
        ->and($future)->not->toHaveKey('trial_end')
        ->and($future['items'])->toContain([
            'price' => 'price_hardening_seat',
            'quantity' => 3,
        ])
        ->and($future['items'][0]['price'])->toBe(
            $target->stripe_price_id,
        )
        ->and($future['start_date'])->toBe(
            $parameters['phases'][0]['end_date'],
        )
        ->and($future['end_date'])->toBeGreaterThan(
            $future['start_date'],
        );
});
