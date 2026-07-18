<?php

namespace App\Contracts;

interface StripeSubscriptionGateway
{
    /**
     * Return the current canonical Stripe representation of a subscription.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $subscriptionId): array;
}
