<?php

namespace Tests\Support;

use App\Contracts\StripeSubscriptionGateway;
use RuntimeException;

class ErinStripeSubscriptionGateway implements StripeSubscriptionGateway
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public array $subscriptions = [];

    /**
     * @var list<string>
     */
    public array $retrieved = [];

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function put(array $snapshot): void
    {
        $subscriptionId = $snapshot['id'] ?? null;
        if (! is_string($subscriptionId) || $subscriptionId === '') {
            throw new RuntimeException('Der Test-Snapshot benötigt eine Subscription-ID.');
        }

        $this->subscriptions[$subscriptionId] = $snapshot;
    }

    public function retrieve(string $subscriptionId): array
    {
        $this->retrieved[] = $subscriptionId;

        return $this->subscriptions[$subscriptionId]
            ?? throw new RuntimeException("Kein Stripe-Test-Snapshot für {$subscriptionId}.");
    }
}
