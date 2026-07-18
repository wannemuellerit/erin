<?php

namespace App\Services\Billing;

use App\Contracts\StripeBillingChangeGateway;
use Laravel\Cashier\Cashier;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;

class StripeApiBillingChangeGateway implements StripeBillingChangeGateway
{
    public function __construct(
        private readonly ?StripeClient $stripeClient = null,
    ) {}

    public function retrieveSubscription(string $subscriptionId): Subscription
    {
        return $this->client()->subscriptions->retrieve($subscriptionId, [
            'expand' => [
                'items.data.price.product',
                'schedule',
                'latest_invoice',
            ],
        ]);
    }

    public function retrieveSchedule(string $scheduleId): SubscriptionSchedule
    {
        return $this->client()->subscriptionSchedules->retrieve(
            $scheduleId,
            ['expand' => ['phases.items.price']],
        );
    }

    public function createSchedule(
        array $parameters,
        string $idempotencyKey,
    ): SubscriptionSchedule {
        return $this->client()->subscriptionSchedules->create(
            $parameters,
            ['idempotency_key' => $idempotencyKey],
        );
    }

    public function updateSubscription(
        string $subscriptionId,
        array $parameters,
        string $idempotencyKey,
    ): Subscription {
        return $this->client()->subscriptions->update(
            $subscriptionId,
            $parameters,
            ['idempotency_key' => $idempotencyKey],
        );
    }

    public function updateSchedule(
        string $scheduleId,
        array $parameters,
        string $idempotencyKey,
    ): SubscriptionSchedule {
        return $this->client()->subscriptionSchedules->update(
            $scheduleId,
            $parameters,
            ['idempotency_key' => $idempotencyKey],
        );
    }

    private function client(): StripeClient
    {
        return $this->stripeClient ?? Cashier::stripe();
    }
}
