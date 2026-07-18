<?php

namespace App\Listeners;

use App\Models\Company;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\IntegrationEventGuard;
use App\Services\Billing\StripeEnvironment;
use App\Services\Billing\StripePurchaseSignature;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;

class SyncStripePurchase
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly IntegrationEventGuard $events,
        private readonly StripeEnvironment $environment,
        private readonly StripePurchaseSignature $purchaseSignature,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;

        if (
            ($payload['type'] ?? null) !== 'checkout.session.completed'
            || ! $this->environment->acceptsEventMode($payload['livemode'] ?? null)
        ) {
            return;
        }

        $data = $payload['data']['object'] ?? [];
        $metadata = $data['metadata'] ?? [];

        if (
            ! is_array($data)
            || ! is_array($metadata)
            || ($metadata['purchase_type'] ?? null) !== 'visa_credits'
            || ($data['mode'] ?? null) !== 'payment'
            || ($data['payment_status'] ?? null) !== 'paid'
        ) {
            return;
        }

        if (! $this->purchaseSignature->verify($metadata)) {
            Log::warning(
                'Stripe-Visakauf mit ungültiger Erin-Signatur wurde verworfen.',
                [
                    'event_id_hash' => hash(
                        'sha256',
                        (string) ($payload['id'] ?? ''),
                    ),
                ],
            );

            return;
        }

        $this->events->once('stripe:received', $payload, function (array $payload): void {
            $data = $payload['data']['object'] ?? [];
            $metadata = $data['metadata'] ?? [];
            if (! is_array($data) || ! is_array($metadata)) {
                return;
            }
            $companyId = filter_var(
                $metadata['company_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            $credits = filter_var(
                $metadata['credits'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 100]],
            );
            $stripeReference = $data['payment_intent'] ?? null;
            if (
                ! is_int($companyId)
                || ! is_int($credits)
                || ! is_string($stripeReference)
                || ! str_starts_with($stripeReference, 'pi_')
            ) {
                return;
            }

            $company = Company::query()->findOrFail($companyId);
            $this->entitlements->grantPurchasedVisaCredits(
                $company,
                $credits,
                $stripeReference,
            );
        });
    }
}
