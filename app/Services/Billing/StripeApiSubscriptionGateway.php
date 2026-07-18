<?php

namespace App\Services\Billing;

use App\Contracts\StripeSubscriptionGateway;
use Laravel\Cashier\Cashier;

class StripeApiSubscriptionGateway implements StripeSubscriptionGateway
{
    public function retrieve(string $subscriptionId): array
    {
        $subscription = Cashier::stripe()->subscriptions->retrieve(
            $subscriptionId,
            ['expand' => [
                'items.data.price',
                'pending_update.subscription_items.price',
                'schedule.phases.items.price',
            ]],
        );

        return $subscription->toArray();
    }
}
