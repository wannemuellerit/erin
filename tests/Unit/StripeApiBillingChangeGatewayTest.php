<?php

use App\Services\Billing\StripeApiBillingChangeGateway;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;

it('sends request-level no-proration and phase-level payloads to Stripe', function () {
    $service = new class
    {
        /** @var list<array<string, mixed>> */
        public array $calls = [];

        /**
         * @param  array<string, mixed>  $parameters
         * @param  array<string, string>  $options
         */
        public function update(
            string $scheduleId,
            array $parameters,
            array $options,
        ): SubscriptionSchedule {
            $this->calls[] = [
                'schedule_id' => $scheduleId,
                'parameters' => $parameters,
                'options' => $options,
            ];

            return SubscriptionSchedule::constructFrom([
                'id' => $scheduleId,
                'object' => 'subscription_schedule',
            ]);
        }
    };
    $client = new class($service) extends StripeClient
    {
        public function __construct(
            private readonly object $scheduleService,
        ) {}

        public function __get($name)
        {
            if ($name !== 'subscriptionSchedules') {
                throw new RuntimeException(
                    'Unerwarteter Stripe-Servicezugriff im Test.',
                );
            }

            return $this->scheduleService;
        }
    };
    $phases = [[
        'items' => [[
            'price' => 'price_gateway_target',
            'quantity' => 1,
        ]],
        'proration_behavior' => 'none',
    ]];
    $parameters = [
        'end_behavior' => 'cancel',
        'phases' => $phases,
        'proration_behavior' => 'none',
    ];

    $result = (new StripeApiBillingChangeGateway($client))->updateSchedule(
        'sub_sched_gateway',
        $parameters,
        'billing-change-idempotency',
    );

    expect($result->id)->toBe('sub_sched_gateway')
        ->and($service->calls)->toHaveCount(1)
        ->and($service->calls[0])->toBe([
            'schedule_id' => 'sub_sched_gateway',
            'parameters' => $parameters,
            'options' => [
                'idempotency_key' => 'billing-change-idempotency',
            ],
        ]);
});

it('passes the complete immediate upgrade payload through without provider defaults', function () {
    $service = new class
    {
        /** @var list<array<string, mixed>> */
        public array $calls = [];

        /**
         * @param  array<string, mixed>  $parameters
         * @param  array<string, string>  $options
         */
        public function update(
            string $subscriptionId,
            array $parameters,
            array $options,
        ): Subscription {
            $this->calls[] = [
                'subscription_id' => $subscriptionId,
                'parameters' => $parameters,
                'options' => $options,
            ];

            return Subscription::constructFrom([
                'id' => $subscriptionId,
                'object' => 'subscription',
            ]);
        }
    };
    $client = new class($service) extends StripeClient
    {
        public function __construct(
            private readonly object $subscriptionService,
        ) {}

        public function __get($name)
        {
            if ($name !== 'subscriptions') {
                throw new RuntimeException(
                    'Unerwarteter Stripe-Servicezugriff im Test.',
                );
            }

            return $this->subscriptionService;
        }
    };
    $parameters = [
        'expand' => [
            'items.data.price.product',
            'latest_invoice',
        ],
        'items' => [[
            'id' => 'si_gateway_base',
            'price' => 'price_gateway_target',
            'quantity' => 1,
        ]],
        'payment_behavior' => 'allow_incomplete',
        'proration_behavior' => 'always_invoice',
    ];

    $result = (new StripeApiBillingChangeGateway($client))
        ->updateSubscription(
            'sub_gateway',
            $parameters,
            'upgrade-idempotency',
        );

    expect($result->id)->toBe('sub_gateway')
        ->and($service->calls)->toBe([[
            'subscription_id' => 'sub_gateway',
            'parameters' => $parameters,
            'options' => [
                'idempotency_key' => 'upgrade-idempotency',
            ],
        ]])
        ->and($service->calls[0]['parameters'])
        ->not->toHaveKey('cancel_at_period_end');
});
