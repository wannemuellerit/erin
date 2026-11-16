<?php

namespace App\Listeners;

use App\Models\Company;
use App\Models\EntitlementLedger;
use App\Services\Billing\IntegrationEventGuard;
use App\Services\Billing\StripeEnvironment;
use App\Services\Billing\StripePurchaseSignature;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Events\WebhookReceived;

class SyncStripeRefund
{
    public function __construct(
        private readonly IntegrationEventGuard $events,
        private readonly StripeEnvironment $environment,
        private readonly StripePurchaseSignature $purchaseSignature,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        if (
            ($payload['type'] ?? null) !== 'charge.refunded'
            || ! $this->environment->acceptsEventMode($payload['livemode'] ?? null)
        ) {
            return;
        }

        $this->events->once('stripe:refund', $payload, function (array $payload): void {
            $charge = $payload['data']['object'] ?? null;
            if (! is_array($charge) || ($charge['refunded'] ?? false) !== true) {
                return;
            }
            $paymentIntent = $charge['payment_intent'] ?? null;
            $chargeId = $charge['id'] ?? null;
            $metadata = $charge['metadata'] ?? [];
            if (
                ! is_string($paymentIntent)
                || preg_match('/^pi_[A-Za-z0-9_]+$/', $paymentIntent) !== 1
                || ! is_string($chargeId)
                || preg_match('/^ch_[A-Za-z0-9_]+$/', $chargeId) !== 1
            ) {
                return;
            }

            DB::transaction(function () use ($chargeId, $metadata, $paymentIntent): void {
                $purchase = EntitlementLedger::query()
                    ->where('stripe_payment_intent_id', $paymentIntent)
                    ->first();
                $companyId = $purchase?->company_id;
                $credits = $purchase?->amount;
                if (! $purchase instanceof EntitlementLedger) {
                    if (! is_array($metadata) || ! $this->purchaseSignature->verify($metadata)) {
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
                }
                if (! is_int($companyId) || ! is_int($credits)) {
                    return;
                }

                Company::query()->lockForUpdate()->findOrFail($companyId);
                $purchase = EntitlementLedger::query()
                    ->where('stripe_payment_intent_id', $paymentIntent)
                    ->first();
                if ($purchase instanceof EntitlementLedger && (
                    $purchase->company_id !== $companyId
                    || $purchase->amount !== $credits
                )) {
                    return;
                }
                $alreadyRefunded = EntitlementLedger::query()
                    ->where('company_id', $companyId)
                    ->where('resource', 'visa')
                    ->where('source', 'stripe_refund')
                    ->where('metadata->stripe_reference', $paymentIntent)
                    ->exists();
                if ($alreadyRefunded) {
                    return;
                }

                EntitlementLedger::query()->create([
                    'company_id' => $companyId,
                    'resource' => 'visa',
                    'amount' => -$credits,
                    'source' => 'stripe_refund',
                    'reference_type' => 'stripe_payment_refund',
                    'metadata' => [
                        'stripe_reference' => $paymentIntent,
                        'stripe_charge' => $chargeId,
                    ],
                ]);
            }, 3);
        });
    }
}
