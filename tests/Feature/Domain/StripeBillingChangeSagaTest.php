<?php

use App\Contracts\StripeBillingChangeGateway;
use App\Contracts\StripeSubscriptionGateway;
use App\Enums\CompanyStatus;
use App\Models\BillingChangeIntent;
use App\Models\Company;
use App\Models\Plan;
use App\Models\PlanStripePrice;
use App\Models\StripeAddonPrice;
use App\Services\Billing\BillingPlanChangeManager;
use App\Services\Billing\PlanStripePriceRegistry;
use App\Services\Billing\StripeSubscriptionItemClassifier;
use App\Services\Billing\StripeSubscriptionSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;
use Tests\Support\ErinStripeBillingChangeGateway;
use Tests\Support\ErinStripeSubscriptionGateway;

uses(RefreshDatabase::class);

/**
 * @param  list<array<string, mixed>>  $items
 */
function erinSagaSubscription(
    Company $company,
    array $items,
    ?string $scheduleId = null,
): Subscription {
    return Subscription::constructFrom([
        'id' => $company->stripe_subscription_id,
        'object' => 'subscription',
        'customer' => $company->stripe_id,
        'status' => 'active',
        'schedule' => $scheduleId,
        'items' => [
            'object' => 'list',
            'data' => $items,
        ],
    ]);
}

/**
 * @return array<string, mixed>
 */
function erinSagaItem(
    string $id,
    string $priceId,
    string $productId,
    int $quantity,
    int $startsAt,
    int $endsAt,
): array {
    return [
        'id' => $id,
        'object' => 'subscription_item',
        'quantity' => $quantity,
        'current_period_start' => $startsAt,
        'current_period_end' => $endsAt,
        'price' => [
            'id' => $priceId,
            'object' => 'price',
            'product' => $productId,
        ],
    ];
}

/**
 * @return array{0: Company, 1: Plan, 2: Plan}
 */
function erinSagaCompanyAndPlans(): array
{
    $current = Plan::factory()->create([
        'name' => 'Business',
        'price_cents' => 349_900,
        'term_months' => 4,
        'stripe_product_id' => 'prod_saga_business',
        'stripe_price_id' => 'price_saga_business',
    ]);
    $target = Plan::factory()->create([
        'name' => 'Premium',
        'price_cents' => 499_900,
        'term_months' => 6,
        'stripe_product_id' => 'prod_saga_premium',
        'stripe_price_id' => 'price_saga_premium',
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => $current->getKey(),
        'stripe_id' => 'cus_saga_company',
        'stripe_subscription_id' => 'sub_saga_company',
        'status' => CompanyStatus::Active,
        'subscription_status' => 'active',
    ]);

    return [$company, $current, $target];
}

beforeEach(function () {
    config()->set('services.stripe.seat_product_id', 'prod_saga_seats');
    config()->set('services.stripe.seat_price_id', 'price_saga_seats');
    StripeAddonPrice::query()->create([
        'code' => 'recruiter_seat',
        'stripe_product_id' => 'prod_saga_seats',
        'stripe_price_id' => 'price_saga_seats',
        'is_enabled' => true,
        'activated_at' => now(),
    ]);
});

it('upgrades an attached schedule without releasing cancellation or future planning', function () {
    [$company, $current, $target] = erinSagaCompanyAndPlans();
    $future = Plan::factory()->create([
        'name' => 'Basic',
        'price_cents' => 299_900,
        'term_months' => 2,
        'stripe_product_id' => 'prod_saga_future_basic',
        'stripe_price_id' => 'price_saga_future_basic',
    ]);
    $startsAt = now()->startOfDay()->getTimestamp();
    $endsAt = now()->addMonths(4)->startOfDay()->getTimestamp();
    $futureEndsAt = now()->addMonths(6)->startOfDay()->getTimestamp();
    $subscription = erinSagaSubscription($company, [
        erinSagaItem(
            'si_saga_seats',
            'price_saga_seats',
            'prod_saga_seats',
            7,
            $startsAt,
            $endsAt,
        ),
        erinSagaItem(
            'si_saga_base',
            'price_saga_business',
            'prod_saga_business',
            1,
            $startsAt,
            $endsAt,
        ),
    ], 'sub_sched_saga_existing');
    $schedule = SubscriptionSchedule::constructFrom([
        'id' => 'sub_sched_saga_existing',
        'object' => 'subscription_schedule',
        'customer' => $company->stripe_id,
        'subscription' => $company->stripe_subscription_id,
        'status' => 'active',
        'end_behavior' => 'cancel',
        'current_phase' => [
            'start_date' => $startsAt,
            'end_date' => $endsAt,
        ],
        'phases' => [
            [
                'start_date' => $startsAt,
                'end_date' => $endsAt,
                'discounts' => [[
                    'promotion_code' => 'promo_current_keep',
                ]],
                'items' => [
                    [
                        'price' => 'price_saga_business',
                        'quantity' => 1,
                    ],
                    [
                        'price' => 'price_saga_seats',
                        'quantity' => 7,
                    ],
                ],
            ],
            [
                'start_date' => $endsAt,
                'end_date' => $futureEndsAt,
                'discounts' => [[
                    'promotion_code' => 'promo_future_keep',
                ]],
                'items' => [
                    [
                        'price' => 'price_saga_future_basic',
                        'quantity' => 1,
                    ],
                    [
                        'price' => 'price_saga_seats',
                        'quantity' => 3,
                    ],
                ],
            ],
        ],
    ]);
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

    expect($intent->status)->toBe('applied')
        ->and(BillingChangeIntent::query()->count())->toBe(1)
        ->and($gateway->updateSubscriptionCalls)->toBeEmpty()
        ->and($gateway->updateScheduleCalls)->toHaveCount(1)
        ->and($gateway->subscription->items->data)->toHaveCount(2)
        ->and($gateway->subscription->items->data[0]->price->id)
        ->toBe('price_saga_seats')
        ->and($gateway->subscription->items->data[0]->quantity)->toBe(7)
        ->and($gateway->subscription->items->data[1]->price->id)
        ->toBe('price_saga_premium')
        ->and($company->fresh()?->current_plan_id)->toBe($target->getKey())
        ->and($company->fresh()?->pending_plan_id)->toBeNull();
    $parameters = $gateway->updateScheduleCalls[0]['parameters'];
    expect($parameters['end_behavior'])->toBe('cancel')
        ->and($parameters['proration_behavior'])->toBe('always_invoice')
        ->and($parameters['phases'][0]['items'][0]['price'])
        ->toBe('price_saga_premium')
        ->and($parameters['phases'][0]['items'][1]['quantity'])->toBe(7)
        ->and($parameters['phases'][1]['items'][0]['price'])
        ->toBe('price_saga_future_basic')
        ->and($parameters['phases'][1]['items'][1]['quantity'])->toBe(3)
        ->and($parameters['phases'][1]['discounts'])->toBe([[
            'promotion_code' => 'promo_future_keep',
        ]])
        ->and(data_get(
            $intent->context,
            'remote_operations.schedule_upgrade.payload_sha256',
        ))->toBeString()
        ->and($future->exists)->toBeTrue();

    app(BillingPlanChangeManager::class)->reconcile($intent);
    expect($gateway->updateScheduleCalls)->toHaveCount(1);
});

it('preserves provider-supported schedule and item settings while replacing only base prices', function () {
    [$company, $business, $premium] = erinSagaCompanyAndPlans();
    $basic = Plan::factory()->create([
        'name' => 'Basic',
        'price_cents' => 299_900,
        'term_months' => 2,
        'stripe_product_id' => 'prod_saga_basic',
        'stripe_price_id' => 'price_saga_basic',
    ]);
    StripeAddonPrice::query()->create([
        'code' => 'compliance',
        'stripe_product_id' => 'prod_saga_compliance',
        'stripe_price_id' => 'price_saga_compliance',
        'is_enabled' => true,
        'activated_at' => now(),
    ]);
    $startsAt = 1_786_000_000;
    $renewsAt = 1_796_000_000;
    $futureEnd = 1_806_000_000;
    $subscription = erinSagaSubscription($company, [
        erinSagaItem(
            'si_saga_compliance',
            'price_saga_compliance',
            'prod_saga_compliance',
            2,
            $startsAt,
            $renewsAt,
        ),
        erinSagaItem(
            'si_saga_base_downgrade',
            'price_saga_business',
            'prod_saga_business',
            1,
            $startsAt,
            $renewsAt,
        ),
        erinSagaItem(
            'si_saga_seats_downgrade',
            'price_saga_seats',
            'prod_saga_seats',
            4,
            $startsAt,
            $renewsAt,
        ),
    ], 'sub_sched_saga_rich');
    $richSettings = [
        'automatic_tax' => [
            'enabled' => true,
            'disabled_reason' => null,
            'liability' => [
                'type' => 'account',
                'account' => [
                    'id' => 'acct_saga_tax',
                    'object' => 'account',
                ],
            ],
        ],
        'collection_method' => 'send_invoice',
        'default_payment_method' => [
            'id' => 'pm_saga_default',
            'object' => 'payment_method',
        ],
        'default_tax_rates' => [[
            'id' => 'txr_saga_default',
            'object' => 'tax_rate',
        ]],
        'discounts' => [[
            'promotion_code' => 'promo_saga_keep',
            'coupon' => null,
            'discount' => null,
        ]],
        'invoice_settings' => [
            'days_until_due' => 14,
            'issuer' => [
                'type' => 'account',
                'account' => [
                    'id' => 'acct_saga_invoice',
                    'object' => 'account',
                ],
            ],
        ],
        'metadata' => ['campaign' => 'saga-2026'],
        'payment_settings' => [
            'save_default_payment_method' => 'on_subscription',
        ],
        'transfer_data' => [
            'amount_percent' => 12.5,
            'destination' => [
                'id' => 'acct_saga_destination',
                'object' => 'account',
            ],
        ],
    ];
    $phaseItems = [
        [
            'price' => [
                'id' => 'price_saga_business',
                'object' => 'price',
            ],
            'quantity' => 1,
            'metadata' => ['kind' => 'base'],
            'discounts' => [['promotion_code' => 'promo_saga_item']],
            'tax_rates' => [[
                'id' => 'txr_saga_item',
                'object' => 'tax_rate',
            ]],
        ],
        [
            'price' => [
                'id' => 'price_saga_compliance',
                'object' => 'price',
            ],
            'quantity' => 2,
            'metadata' => ['kind' => 'compliance'],
        ],
        [
            'price' => [
                'id' => 'price_saga_seats',
                'object' => 'price',
            ],
            'quantity' => 4,
            'metadata' => ['kind' => 'seats'],
        ],
    ];
    $schedule = SubscriptionSchedule::constructFrom([
        'id' => 'sub_sched_saga_rich',
        'object' => 'subscription_schedule',
        'customer' => $company->stripe_id,
        'subscription' => $company->stripe_subscription_id,
        'status' => 'active',
        'end_behavior' => 'release',
        'current_phase' => [
            'start_date' => $startsAt,
            'end_date' => $renewsAt,
        ],
        'phases' => [
            [
                ...$richSettings,
                'start_date' => $startsAt,
                'end_date' => $renewsAt,
                'items' => $phaseItems,
            ],
            [
                ...$richSettings,
                'start_date' => $renewsAt,
                'end_date' => $futureEnd,
                'items' => $phaseItems,
            ],
        ],
    ]);
    $gateway = new ErinStripeBillingChangeGateway(
        $subscription,
        $schedule,
    );
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $intent = app(BillingPlanChangeManager::class)->request(
        $company,
        $business,
        $basic,
        null,
        now()->createFromTimestamp($renewsAt),
    );

    expect($intent->status)->toBe('applied')
        ->and($gateway->updateScheduleCalls)->toHaveCount(1);
    $parameters = $gateway->updateScheduleCalls[0]['parameters'];
    expect($parameters['proration_behavior'])->toBe('none')
        ->and($parameters['end_behavior'])->toBe('release');
    $phases = $parameters['phases'];
    expect($phases)->toHaveCount(2)
        ->and($phases[0]['proration_behavior'])->toBe('none')
        ->and($phases[1]['proration_behavior'])->toBe('none')
        ->and($phases[0]['items'][0]['price'])->toBe(
            'price_saga_business',
        )
        ->and($phases[1]['items'][0]['price'])->toBe('price_saga_basic')
        ->and($phases[0]['items'][1]['quantity'])->toBe(2)
        ->and($phases[1]['items'][2]['quantity'])->toBe(4)
        ->and($phases[0]['metadata'])->toBe([
            'campaign' => 'saga-2026',
        ])
        ->and($phases[1]['discounts'])->toBe([[
            'promotion_code' => 'promo_saga_keep',
        ]])
        ->and($phases[0]['default_tax_rates'])->toBe([
            'txr_saga_default',
        ])
        ->and($phases[0]['automatic_tax'])->toBe([
            'enabled' => true,
            'liability' => [
                'account' => 'acct_saga_tax',
                'type' => 'account',
            ],
        ])
        ->and($phases[0]['invoice_settings']['issuer']['account'])
        ->toBe('acct_saga_invoice')
        ->and($phases[0]['transfer_data']['destination'])
        ->toBe('acct_saga_destination')
        ->and($phases[0]['items'][0]['tax_rates'])
        ->toBe(['txr_saga_item'])
        ->and($phases[0]['items'][0]['discounts'])
        ->toBe([['promotion_code' => 'promo_saga_item']])
        ->and($phases[0])->not->toHaveKey('disabled_reason')
        ->and($premium->exists)->toBeTrue();
});

it('recognizes historical base prices with their immutable product binding', function () {
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_history_current',
        'stripe_price_id' => 'price_history_current',
    ]);
    PlanStripePrice::query()->create([
        'plan_id' => $plan->getKey(),
        'stripe_product_id' => 'prod_history_old',
        'stripe_price_id' => 'price_history_old',
        'price_cents' => 299_900,
        'currency' => 'EUR',
        'term_months' => 2,
        'version_hash' => hash('sha256', 'history-old'),
        'source' => 'test',
        'is_current' => false,
        'activated_at' => now()->subYear(),
        'retired_at' => now()->subMonth(),
    ]);
    $item = erinSagaItem(
        'si_history_old',
        'price_history_old',
        'prod_history_old',
        1,
        now()->getTimestamp(),
        now()->addMonths(2)->getTimestamp(),
    );

    $classification = app(StripeSubscriptionItemClassifier::class)
        ->classify([$item]);

    expect($classification['base_plan']?->is($plan))->toBeTrue()
        ->and($classification['base_item']['price'] ?? null)
        ->toBe('price_history_old');

    $item['price']['product'] = 'prod_history_attacker';
    expect(fn () => app(StripeSubscriptionItemClassifier::class)
        ->classify([$item]))
        ->toThrow(RuntimeException::class, 'Produktzuordnung');
});

it('never rewrites or reassigns an immutable historical Stripe Price', function () {
    $original = Plan::factory()->create([
        'price_cents' => 349_900,
        'term_months' => 4,
        'stripe_product_id' => 'prod_immutable_business',
        'stripe_price_id' => 'price_immutable_business',
    ]);
    $registry = app(PlanStripePriceRegistry::class);
    $registry->record($original, 'test_original');
    $registry->record($original->refresh(), 'later_observation');
    expect(PlanStripePrice::query()
        ->where('stripe_price_id', 'price_immutable_business')
        ->value('source'))->toBe('test_original');

    $original->forceFill(['price_cents' => 123_456])->save();
    expect(fn () => $registry->record(
        $original->refresh(),
        'test_mutation',
    ))->toThrow(LogicException::class, 'unveränderlich');

    $original->forceFill([
        'price_cents' => 349_900,
        'stripe_product_id' => 'prod_immutable_business_v2',
        'stripe_price_id' => 'price_immutable_business_v2',
    ])->save();
    $other = Plan::factory()->create([
        'price_cents' => 349_900,
        'term_months' => 4,
        'stripe_product_id' => 'prod_immutable_business',
        'stripe_price_id' => 'price_immutable_business',
    ]);
    expect(fn () => $registry->record(
        $other,
        'test_reassignment',
    ))->toThrow(LogicException::class, 'keinem anderen Paket');

    $version = PlanStripePrice::query()
        ->where('stripe_price_id', 'price_immutable_business')
        ->sole();
    expect($version->plan_id)->toBe($original->getKey())
        ->and($version->price_cents)->toBe(349_900)
        ->and($version->source)->toBe('test_original');
});

it('rejects unknown and duplicate subscription positions before any remote mutation', function (
    Closure $mutate,
    string $message,
) {
    [$company, $current, $target] = erinSagaCompanyAndPlans();
    $startsAt = now()->getTimestamp();
    $endsAt = now()->addMonths(4)->getTimestamp();
    $items = [
        erinSagaItem(
            'si_classifier_base',
            'price_saga_business',
            'prod_saga_business',
            1,
            $startsAt,
            $endsAt,
        ),
        erinSagaItem(
            'si_classifier_seat',
            'price_saga_seats',
            'prod_saga_seats',
            2,
            $startsAt,
            $endsAt,
        ),
    ];
    $mutate($items);
    $gateway = new ErinStripeBillingChangeGateway(
        erinSagaSubscription($company, $items),
    );
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    expect(fn () => app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    ))->toThrow(RuntimeException::class, $message)
        ->and(BillingChangeIntent::query()->count())->toBe(0)
        ->and($gateway->updateSubscriptionCalls)->toBeEmpty()
        ->and($gateway->updateScheduleCalls)->toBeEmpty();
    expect(fn () => app(StripeSubscriptionItemClassifier::class)
        ->classify($items))->toThrow(RuntimeException::class, $message);
})->with([
    'unbekannte Add-on-Price' => [
        function (array &$items): void {
            $items[1]['price'] = [
                'id' => 'price_attacker',
                'product' => 'prod_attacker',
            ];
        },
        'nicht freigegebene Add-on-Price',
    ],
    'doppelte Price-ID' => [
        function (array &$items): void {
            $items[1]['price'] = $items[0]['price'];
        },
        'doppelte Price-ID',
    ],
    'doppelte Item-ID' => [
        function (array &$items): void {
            $items[1]['id'] = $items[0]['id'];
        },
        'doppelte Item-ID',
    ],
]);

it('uses only the base item period when add-on and subscription periods diverge', function () {
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_period_base',
        'stripe_price_id' => 'price_period_base',
    ]);
    StripeAddonPrice::query()->create([
        'code' => 'period_seat',
        'stripe_product_id' => 'prod_period_seat',
        'stripe_price_id' => 'price_period_seat',
        'is_enabled' => true,
        'activated_at' => now(),
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => null,
        'stripe_id' => 'cus_period_base',
        'status' => CompanyStatus::Pending,
        'subscription_status' => 'incomplete',
    ]);
    $baseStart = now()->startOfDay();
    $baseEnd = $baseStart->copy()->addMonths(4);
    $addOnStart = $baseStart->copy()->addYear();
    $addOnEnd = $addOnStart->copy()->addYear();
    $snapshot = [
        'id' => 'sub_period_base',
        'customer' => $company->stripe_id,
        'created' => now()->getTimestamp(),
        'status' => 'active',
        'metadata' => [
            'type' => 'default',
            'company_id' => (string) $company->getKey(),
        ],
        'current_period_start' => $addOnStart->getTimestamp(),
        'current_period_end' => $addOnEnd->getTimestamp(),
        'items' => ['data' => [
            erinSagaItem(
                'si_period_addon',
                'price_period_seat',
                'prod_period_seat',
                3,
                $addOnStart->getTimestamp(),
                $addOnEnd->getTimestamp(),
            ),
            erinSagaItem(
                'si_period_base',
                'price_period_base',
                'prod_period_base',
                1,
                $baseStart->getTimestamp(),
                $baseEnd->getTimestamp(),
            ),
        ]],
    ];
    $gateway = new ErinStripeSubscriptionGateway;
    $gateway->put($snapshot);
    app()->instance(StripeSubscriptionGateway::class, $gateway);

    app(StripeSubscriptionSynchronizer::class)->synchronize([
        'id' => 'evt_period_base',
        'type' => 'customer.subscription.created',
        'data' => ['object' => $snapshot],
    ]);

    $company->refresh();
    expect($company->subscription_started_at?->getTimestamp())
        ->toBe($baseStart->getTimestamp())
        ->and($company->subscription_renews_at?->getTimestamp())
        ->toBe($baseEnd->getTimestamp())
        ->and($company->subscription('default')?->current_period_start)
        ->toBe($baseStart->format('Y-m-d H:i:s'))
        ->and($company->subscription('default')?->current_period_end)
        ->toBe($baseEnd->format('Y-m-d H:i:s'));
});

it('retries one durable intent with the same idempotency key after a provider failure', function () {
    [$company, $current, $target] = erinSagaCompanyAndPlans();
    $startsAt = now()->getTimestamp();
    $endsAt = now()->addMonths(4)->getTimestamp();
    $gateway = new ErinStripeBillingChangeGateway(
        erinSagaSubscription($company, [
            erinSagaItem(
                'si_retry_base',
                'price_saga_business',
                'prod_saga_business',
                1,
                $startsAt,
                $endsAt,
            ),
        ]),
    );
    $gateway->subscriptionUpdateFailuresRemaining = 1;
    app()->instance(StripeBillingChangeGateway::class, $gateway);

    $first = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );
    expect($first->status)->toBe('reconcile')
        ->and($gateway->updateSubscriptionCalls)->toHaveCount(1)
        ->and(BillingChangeIntent::query()->count())->toBe(1);

    $second = app(BillingPlanChangeManager::class)->request(
        $company,
        $current,
        $target,
        null,
        now(),
    );

    expect($second->status)->toBe('applied')
        ->and(BillingChangeIntent::query()->count())->toBe(1)
        ->and($gateway->updateSubscriptionCalls)->toHaveCount(2)
        ->and($gateway->updateSubscriptionCalls[0]['idempotency_key'])
        ->toBe($gateway->updateSubscriptionCalls[1]['idempotency_key'])
        ->and($gateway->updateSubscriptionCalls[0]['parameters'])
        ->toBe($gateway->updateSubscriptionCalls[1]['parameters'])
        ->and($second->attempts)->toBe(2)
        ->and(Artisan::call('erin:stripe:reconcile-billing'))
        ->toBe(0);
});

it('reconciles after remote success followed by a local finalization failure without changing the base twice', function () {
    [$company, $current, $target] = erinSagaCompanyAndPlans();
    $startsAt = now()->getTimestamp();
    $endsAt = now()->addMonths(4)->getTimestamp();
    $gateway = new ErinStripeBillingChangeGateway(
        erinSagaSubscription($company, [
            erinSagaItem(
                'si_db_failure_base',
                'price_saga_business',
                'prod_saga_business',
                1,
                $startsAt,
                $endsAt,
            ),
        ]),
    );
    app()->instance(StripeBillingChangeGateway::class, $gateway);
    $eventName = 'eloquent.updating: '.Company::class;
    $failOnce = true;
    Event::listen($eventName, function (Company $updating) use (
        &$failOnce,
    ): void {
        if ($failOnce && $updating->isDirty('current_plan_id')) {
            $failOnce = false;
            throw new RuntimeException('Simulierter lokaler DB-Abschlussfehler.');
        }
    });

    try {
        $intent = app(BillingPlanChangeManager::class)->request(
            $company,
            $current,
            $target,
            null,
            now(),
        );
    } finally {
        Event::forget($eventName);
    }

    expect($intent->status)->toBe('reconcile')
        ->and($gateway->updateSubscriptionCalls)->toHaveCount(1)
        ->and($gateway->subscription->items->data[0]->price->id)
        ->toBe('price_saga_premium');

    $reconciled = app(BillingPlanChangeManager::class)->reconcile($intent);
    expect($reconciled->status)->toBe('applied')
        ->and($gateway->updateSubscriptionCalls)->toHaveCount(1)
        ->and($gateway->subscriptionMutations)->toBe(1)
        ->and($company->fresh()?->current_plan_id)
        ->toBe($target->getKey())
        ->and($company->fresh()?->pending_plan_id)->toBeNull();
});
