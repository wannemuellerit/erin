<?php

use App\Models\Company;
use App\Models\EntitlementLedger;
use App\Models\IntegrationReceipt;
use App\Services\Billing\StripePurchaseSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $payload
 */
function erinPostCheckoutWebhook(
    array $payload,
    string $secret,
): TestResponse {
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = now()->getTimestamp();
    $signature = hash_hmac(
        'sha256',
        $timestamp.'.'.$json,
        $secret,
    );

    return test()->call(
        'POST',
        route('cashier.webhook'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ],
        $json,
    );
}

/**
 * @return array<string, mixed>
 */
function erinSignedCheckoutPayload(
    Company $company,
    string $eventId,
    string $paymentIntentId,
    int $credits = 5,
): array {
    $priceId = 'price_http_checkout_visa';

    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'created' => now()->getTimestamp(),
        'data' => ['object' => [
            'id' => 'cs_'.$eventId,
            'object' => 'checkout.session',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'payment_intent' => $paymentIntentId,
            'metadata' => [
                'purchase_type' => 'visa_credits',
                'company_id' => (string) $company->getKey(),
                'credits' => (string) $credits,
                'price_id' => $priceId,
                'erin_signature_version' => StripePurchaseSignature::VERSION,
                'erin_purchase_signature' => app(
                    StripePurchaseSignature::class,
                )->sign(
                    (int) $company->getKey(),
                    $credits,
                    $priceId,
                ),
            ],
        ]],
    ];
}

beforeEach(function () {
    config()->set('app.key', 'base64:'.base64_encode(
        'erin-http-checkout-signing-key-32',
    ));
    config()->set('cashier.secret', 'sk_test_http_checkout');
    config()->set(
        'cashier.webhook.secret',
        'whsec_http_checkout_acceptance',
    );
    config()->set('cashier.webhook.tolerance', 300);
});

it('credits a signed completed Checkout Session exactly once through the public HTTP webhook', function () {
    $company = Company::factory()->create();
    $payload = erinSignedCheckoutPayload(
        $company,
        'evt_http_checkout_completed',
        'pi_http_checkout_completed',
        5,
    );

    erinPostCheckoutWebhook(
        $payload,
        'whsec_http_checkout_acceptance',
    )->assertOk();
    erinPostCheckoutWebhook(
        $payload,
        'whsec_http_checkout_acceptance',
    )->assertOk();

    expect(EntitlementLedger::query()
        ->where('stripe_payment_intent_id', 'pi_http_checkout_completed')
        ->count())->toBe(1)
        ->and(EntitlementLedger::query()
            ->where('stripe_payment_intent_id', 'pi_http_checkout_completed')
            ->sum('amount'))->toEqual(5)
        ->and(IntegrationReceipt::query()
            ->where('provider', 'stripe:received')
            ->where('event_id', 'evt_http_checkout_completed')
            ->value('status'))->toBe('processed');
});

it('ignores metadata tampering even when the HTTP Stripe signature is valid', function () {
    $company = Company::factory()->create();
    $payload = erinSignedCheckoutPayload(
        $company,
        'evt_http_checkout_tampered',
        'pi_http_checkout_tampered',
        5,
    );
    $payload['data']['object']['metadata']['credits'] = '50';

    erinPostCheckoutWebhook(
        $payload,
        'whsec_http_checkout_acceptance',
    )->assertOk();

    expect(EntitlementLedger::query()->count())->toBe(0)
        ->and(IntegrationReceipt::query()
            ->where('provider', 'stripe:received')
            ->count())->toBe(0);
});

it('rejects an otherwise valid Checkout event signed with the wrong webhook secret', function () {
    $company = Company::factory()->create();
    $payload = erinSignedCheckoutPayload(
        $company,
        'evt_http_checkout_wrong_secret',
        'pi_http_checkout_wrong_secret',
    );

    erinPostCheckoutWebhook(
        $payload,
        'whsec_http_checkout_wrong',
    )->assertForbidden();

    expect(EntitlementLedger::query()->count())->toBe(0)
        ->and(IntegrationReceipt::query()->count())->toBe(0);
});
