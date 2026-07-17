<?php

namespace App\Listeners;

use App\Models\Company;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\IntegrationEventGuard;
use Laravel\Cashier\Events\WebhookReceived;

class SyncStripePurchase
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly IntegrationEventGuard $events,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;

        if (($payload['type'] ?? null) !== 'checkout.session.completed') {
            return;
        }

        $data = $payload['data']['object'] ?? [];
        $metadata = $data['metadata'] ?? [];

        if (($metadata['purchase_type'] ?? null) !== 'visa_credits' || ($data['payment_status'] ?? null) !== 'paid') {
            return;
        }

        $this->events->once('stripe:received', $payload, function (array $payload): void {
            $data = $payload['data']['object'] ?? [];
            $metadata = $data['metadata'] ?? [];
            $company = Company::query()->findOrFail((int) ($metadata['company_id'] ?? 0));
            $this->entitlements->grantPurchasedVisaCredits(
                $company,
                max(1, (int) ($metadata['credits'] ?? 1)),
                (string) ($data['payment_intent'] ?? $data['id']),
            );
        });
    }
}
