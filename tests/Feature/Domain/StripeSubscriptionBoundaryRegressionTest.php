<?php

use App\Contracts\StripeSubscriptionGateway;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\IntegrationReceipt;
use App\Models\Plan;
use App\Models\StripeAddonPrice;
use App\Services\Billing\StripeSubscriptionScheduleBuilder;
use App\Services\Billing\StripeSubscriptionSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Cashier\Subscription as CashierSubscription;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;
use Tests\Support\ErinStripeSubscriptionGateway;

uses(RefreshDatabase::class);

/**
 * @param  list<array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function erinBoundarySubscriptionSnapshot(
    Company $company,
    string $subscriptionId,
    array $items,
    string $status = 'active',
): array {
    $startsAt = now()->startOfDay()->getTimestamp();
    $endsAt = now()->startOfDay()->addMonths(4)->getTimestamp();

    return [
        'id' => $subscriptionId,
        'object' => 'subscription',
        'customer' => $company->stripe_id,
        'created' => $startsAt,
        'status' => $status,
        'metadata' => [
            'type' => 'default',
            'company_id' => (string) $company->getKey(),
            'erin_subscription_generation' => '1',
        ],
        'trial_end' => null,
        'cancel_at_period_end' => false,
        'current_period_start' => $startsAt,
        'current_period_end' => $endsAt,
        'ended_at' => null,
        'items' => ['data' => $items],
    ];
}

/**
 * @param  array<string, mixed>  $snapshot
 * @return array<string, mixed>
 */
function erinBoundarySubscriptionEvent(
    string $eventId,
    array $snapshot,
): array {
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => 'customer.subscription.updated',
        'livemode' => false,
        'created' => now()->getTimestamp(),
        'data' => ['object' => $snapshot],
    ];
}

/**
 * @return array{0: Company, 1: Plan, 2: CashierSubscription}
 */
function erinBoundaryExistingSubscription(): array
{
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_boundary_current',
        'stripe_price_id' => 'price_boundary_current',
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => $plan->getKey(),
        'stripe_id' => 'cus_boundary',
        'stripe_subscription_id' => 'sub_boundary',
        'stripe_subscription_generation' => 1,
        'status' => CompanyStatus::Active,
        'subscription_status' => 'active',
    ]);
    $subscription = $company->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_boundary',
        'stripe_status' => 'active',
        'stripe_price' => $plan->stripe_price_id,
        'quantity' => 1,
    ]);
    $subscription->items()->create([
        'stripe_id' => 'si_boundary_original',
        'stripe_product' => $plan->stripe_product_id,
        'stripe_price' => $plan->stripe_price_id,
        'quantity' => 1,
    ]);

    return [$company, $plan, $subscription];
}

beforeEach(function () {
    config()->set('cashier.secret', 'sk_test_subscription_boundaries');
    config()->set('cashier.webhook.secret', 'whsec_subscription_boundaries');
    config()->set('cashier.webhook.tolerance', 300);
    app()->instance(
        StripeSubscriptionGateway::class,
        new ErinStripeSubscriptionGateway,
    );
});

it('preserves every recurring add-on and quantity across both downgrade phases regardless of item order', function () {
    foreach ([
        ['compliance', 'prod_addon_compliance', 'price_addon_compliance'],
        ['seats', 'prod_addon_seats', 'price_addon_seats'],
    ] as [$code, $productId, $priceId]) {
        StripeAddonPrice::query()->create([
            'code' => $code,
            'stripe_product_id' => $productId,
            'stripe_price_id' => $priceId,
            'is_enabled' => true,
            'activated_at' => now(),
        ]);
    }
    $current = Plan::factory()->create([
        'stripe_product_id' => 'prod_schedule_business',
        'stripe_price_id' => 'price_schedule_business',
    ]);
    $target = Plan::factory()->create([
        'stripe_product_id' => 'prod_schedule_basic',
        'stripe_price_id' => 'price_schedule_basic',
    ]);
    $subscription = Subscription::constructFrom([
        'id' => 'sub_schedule_boundary',
        'object' => 'subscription',
        'customer' => 'cus_schedule_boundary',
        'status' => 'active',
        'schedule' => 'sub_sched_boundary',
        'items' => [
            'object' => 'list',
            'data' => [
                [
                    'id' => 'si_schedule_seats',
                    'object' => 'subscription_item',
                    'quantity' => 7,
                    'price' => [
                        'id' => 'price_addon_seats',
                        'object' => 'price',
                        'product' => 'prod_addon_seats',
                    ],
                ],
                [
                    'id' => 'si_schedule_base',
                    'object' => 'subscription_item',
                    'quantity' => 1,
                    'price' => [
                        'id' => $current->stripe_price_id,
                        'object' => 'price',
                        'product' => $current->stripe_product_id,
                    ],
                ],
                [
                    'id' => 'si_schedule_compliance',
                    'object' => 'subscription_item',
                    'quantity' => 2,
                    'price' => [
                        'id' => 'price_addon_compliance',
                        'object' => 'price',
                        'product' => 'prod_addon_compliance',
                    ],
                ],
            ],
        ],
    ]);
    $schedule = SubscriptionSchedule::constructFrom([
        'id' => 'sub_sched_boundary',
        'object' => 'subscription_schedule',
        'customer' => 'cus_schedule_boundary',
        'subscription' => 'sub_schedule_boundary',
        'status' => 'active',
        'end_behavior' => 'release',
        'current_phase' => [
            'start_date' => 1_786_000_000,
            'end_date' => 1_796_000_000,
        ],
        'phases' => [
            [
                'start_date' => 1_786_000_000,
                'end_date' => 1_796_000_000,
                'items' => [
                    [
                        'price' => 'price_addon_seats',
                        'quantity' => 7,
                    ],
                    [
                        'price' => 'price_schedule_business',
                        'quantity' => 1,
                    ],
                    [
                        'price' => 'price_addon_compliance',
                        'quantity' => 2,
                    ],
                ],
            ],
            [
                'start_date' => 1_796_000_000,
                'end_date' => 1_806_000_000,
                'items' => [
                    [
                        'price' => 'price_addon_seats',
                        'quantity' => 9,
                    ],
                    [
                        'price' => 'price_schedule_business',
                        'quantity' => 1,
                    ],
                    [
                        'price' => 'price_addon_compliance',
                        'quantity' => 5,
                    ],
                ],
            ],
        ],
    ]);

    $phases = app(StripeSubscriptionScheduleBuilder::class)
        ->downgradePhases($subscription, $schedule, $current, $target);

    $currentAddOns = [
        ['price' => 'price_addon_compliance', 'quantity' => 2],
        ['price' => 'price_addon_seats', 'quantity' => 7],
    ];
    $futureAddOns = [
        ['price' => 'price_addon_compliance', 'quantity' => 5],
        ['price' => 'price_addon_seats', 'quantity' => 9],
    ];
    expect($phases)->toHaveCount(2)
        ->and($phases[0]['items'])->toBe([
            ['price' => 'price_schedule_business', 'quantity' => 1],
            ...$currentAddOns,
        ])
        ->and($phases[1]['items'])->toBe([
            ['price' => 'price_schedule_basic', 'quantity' => 1],
            ...$futureAddOns,
        ])
        ->and($phases[0]['start_date'])->toBe(1_786_000_000)
        ->and($phases[0]['end_date'])->toBe(1_796_000_000);
});

it('accepts exactly one base item with quantity one even when an add-on is first', function () {
    StripeAddonPrice::query()->create([
        'code' => 'accept_seats',
        'stripe_product_id' => 'prod_boundary_accept_seats',
        'stripe_price_id' => 'price_boundary_accept_seats',
        'is_enabled' => true,
        'activated_at' => now(),
    ]);
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_boundary_accept',
        'stripe_price_id' => 'price_boundary_accept',
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => null,
        'stripe_id' => 'cus_boundary_accept',
        'status' => CompanyStatus::Pending,
        'subscription_status' => 'incomplete',
    ]);
    $snapshot = erinBoundarySubscriptionSnapshot(
        $company,
        'sub_boundary_accept',
        [
            [
                'id' => 'si_boundary_accept_seats',
                'quantity' => 5,
                'price' => [
                    'id' => 'price_boundary_accept_seats',
                    'product' => 'prod_boundary_accept_seats',
                ],
            ],
            [
                'id' => 'si_boundary_accept_base',
                'quantity' => 1,
                'price' => [
                    'id' => $plan->stripe_price_id,
                    'product' => $plan->stripe_product_id,
                ],
            ],
        ],
    );
    $gateway = app(StripeSubscriptionGateway::class);
    expect($gateway)->toBeInstanceOf(ErinStripeSubscriptionGateway::class);
    $gateway->put($snapshot);

    app(StripeSubscriptionSynchronizer::class)->synchronize(
        erinBoundarySubscriptionEvent('evt_boundary_accept', $snapshot),
    );

    $company->refresh();
    expect($company->status)->toBe(CompanyStatus::Active)
        ->and($company->current_plan_id)->toBe($plan->getKey())
        ->and($company->subscription_status)->toBe('active')
        ->and($company->subscription('default')?->items()->count())->toBe(2)
        ->and($company->subscription('default')?->items()
            ->where('stripe_price', 'price_boundary_accept_seats')
            ->value('quantity'))->toBe(5);
});

it('rejects duplicate or multiplied base items without partially mutating billing', function (
    Closure $mutate,
    string $message,
) {
    [$company, $plan, $subscription] = erinBoundaryExistingSubscription();
    $items = [[
        'id' => 'si_boundary_updated_base',
        'quantity' => 1,
        'price' => [
            'id' => $plan->stripe_price_id,
            'product' => $plan->stripe_product_id,
        ],
    ]];
    $mutate($items);
    $snapshot = erinBoundarySubscriptionSnapshot(
        $company,
        'sub_boundary',
        $items,
        'past_due',
    );
    $gateway = app(StripeSubscriptionGateway::class);
    expect($gateway)->toBeInstanceOf(ErinStripeSubscriptionGateway::class);
    $gateway->put($snapshot);

    expect(fn () => app(StripeSubscriptionSynchronizer::class)->synchronize(
        erinBoundarySubscriptionEvent('evt_boundary_rejected', $snapshot),
    ))->toThrow(RuntimeException::class, $message);

    $company->refresh();
    expect($company->subscription_status)->toBe('active')
        ->and($company->current_plan_id)->toBe($plan->getKey())
        ->and($subscription->fresh()?->stripe_status)->toBe('active')
        ->and($subscription->items()->count())->toBe(1)
        ->and($subscription->items()->sole()->stripe_id)
        ->toBe('si_boundary_original');
})->with([
    'identische Base-Price doppelt' => [
        function (array &$items): void {
            $duplicate = $items[0];
            $duplicate['id'] = 'si_boundary_duplicate_base';
            $items[] = $duplicate;
        },
        'doppelte Price-ID',
    ],
    'Base-Price mit Menge größer eins' => [
        function (array &$items): void {
            $items[0]['quantity'] = 2;
        },
        'Menge 1',
    ],
]);

it('fails closed before parsing, provider access, or receipt creation when the webhook secret is missing', function () {
    config()->set('cashier.webhook.secret', null);
    Event::fake([WebhookReceived::class, WebhookHandled::class]);
    $gateway = app(StripeSubscriptionGateway::class);
    expect($gateway)->toBeInstanceOf(ErinStripeSubscriptionGateway::class);

    $response = $this->call(
        'POST',
        route('cashier.webhook'),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"id":"evt_should_not_be_parsed",',
    );

    $response->assertForbidden();
    expect($gateway->retrieved)->toBe([])
        ->and(IntegrationReceipt::query()->count())->toBe(0);
    Event::assertNotDispatched(WebhookReceived::class);
    Event::assertNotDispatched(WebhookHandled::class);
});
