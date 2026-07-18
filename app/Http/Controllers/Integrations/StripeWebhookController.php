<?php

namespace App\Http\Controllers\Integrations;

use App\Services\Billing\StripeSubscriptionSynchronizer;
use App\Services\Billing\StripeWebhookEventProcessor;
use Illuminate\Http\Request;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

class StripeWebhookController extends CashierWebhookController
{
    /**
     * @var list<string>
     */
    private const SUBSCRIPTION_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function __construct(
        private readonly StripeWebhookEventProcessor $events,
        private readonly StripeSubscriptionSynchronizer $subscriptions,
    ) {
        parent::__construct();
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $type = is_array($payload) && is_string($payload['type'] ?? null)
            ? $payload['type']
            : '';

        if (! in_array($type, self::SUBSCRIPTION_EVENTS, true)) {
            return parent::handleWebhook($request);
        }

        WebhookReceived::dispatch($payload);
        $this->setMaxNetworkRetries();
        $this->events->once(
            $payload,
            function (array $event): void {
                $this->subscriptions->synchronize($event);
            },
        );
        WebhookHandled::dispatch($payload);

        return $this->successMethod();
    }
}
