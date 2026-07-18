<?php

namespace App\Contracts;

use Stripe\Subscription;
use Stripe\SubscriptionSchedule;

interface StripeBillingChangeGateway
{
    public function retrieveSubscription(string $subscriptionId): Subscription;

    public function retrieveSchedule(string $scheduleId): SubscriptionSchedule;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function createSchedule(
        array $parameters,
        string $idempotencyKey,
    ): SubscriptionSchedule;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function updateSubscription(
        string $subscriptionId,
        array $parameters,
        string $idempotencyKey,
    ): Subscription;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function updateSchedule(
        string $scheduleId,
        array $parameters,
        string $idempotencyKey,
    ): SubscriptionSchedule;
}
