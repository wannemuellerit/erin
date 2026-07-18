<?php

namespace App\Http\Controllers\Integrations;

use App\Services\Billing\StripeEnvironment;
use App\Services\Billing\StripeSubscriptionSynchronizer;
use App\Services\Billing\StripeWebhookEventProcessor;
use Illuminate\Http\Request;
use JsonException;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class StripeWebhookController extends CashierWebhookController
{
    /**
     * @var list<string>
     */
    private const SUBSCRIPTION_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.pending_update_applied',
        'customer.subscription.pending_update_expired',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    /**
     * @var list<string>
     */
    private const SUBSCRIPTION_SCHEDULE_EVENTS = [
        'subscription_schedule.canceled',
        'subscription_schedule.completed',
        'subscription_schedule.released',
        'subscription_schedule.updated',
    ];

    public function __construct(
        private readonly StripeWebhookEventProcessor $events,
        private readonly StripeSubscriptionSynchronizer $subscriptions,
        private readonly StripeEnvironment $environment,
    ) {
        parent::__construct();
    }

    public function handleWebhook(Request $request): Response
    {
        if (blank(config('cashier.webhook.secret'))) {
            throw new AccessDeniedHttpException(
                'Der Stripe-Webhook ist ohne konfiguriertes Signaturgeheimnis deaktiviert.',
            );
        }

        try {
            $payload = json_decode(
                $request->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new BadRequestHttpException(
                'Das Stripe-Ereignis enthält kein gültiges JSON.',
                $exception,
            );
        }

        if (! $this->validPayload($payload)) {
            throw new BadRequestHttpException(
                'Das Stripe-Ereignis ist unvollständig oder ungültig.',
            );
        }
        if (! $this->environment->acceptsEventMode($payload['livemode'])) {
            throw new AccessDeniedHttpException(
                'Der Stripe-Ereignismodus stimmt nicht mit der Umgebung überein.',
            );
        }

        $type = $payload['type'];

        $isSubscriptionEvent = in_array(
            $type,
            self::SUBSCRIPTION_EVENTS,
            true,
        );
        $isScheduleEvent = in_array(
            $type,
            self::SUBSCRIPTION_SCHEDULE_EVENTS,
            true,
        );
        if (! $isSubscriptionEvent && ! $isScheduleEvent) {
            return parent::handleWebhook($request);
        }

        WebhookReceived::dispatch($payload);
        $this->setMaxNetworkRetries();
        $this->events->once(
            $payload,
            function (array $event) use ($isScheduleEvent): void {
                if ($isScheduleEvent) {
                    $this->subscriptions->synchronizeSchedule($event);

                    return;
                }

                $this->subscriptions->synchronize($event);
            },
        );
        WebhookHandled::dispatch($payload);

        return $this->successMethod();
    }

    private function validPayload(mixed $payload): bool
    {
        if (
            ! is_array($payload)
            || ! is_string($payload['id'] ?? null)
            || preg_match('/^evt_[A-Za-z0-9_]+$/', $payload['id']) !== 1
            || strlen($payload['id']) > 255
            || ! is_string($payload['type'] ?? null)
            || preg_match('/^[a-z0-9_.]+$/', $payload['type']) !== 1
            || strlen($payload['type']) > 160
            || ! is_bool($payload['livemode'] ?? null)
        ) {
            return false;
        }

        return is_array($payload['data'] ?? null)
            && is_array($payload['data']['object'] ?? null);
    }
}
