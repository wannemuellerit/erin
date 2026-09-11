<?php

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Listeners\SyncStripeInvoice;
use App\Listeners\SyncStripePurchase;
use App\Listeners\SyncStripeRefund;
use App\Models\Company;
use App\Models\CompanyBillingInvoice;
use App\Models\CompanyMembership;
use App\Models\EntitlementLedger;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\PlanStripePriceRegistry;
use App\Services\Billing\StripePurchaseSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Events\WebhookReceived;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cashier.secret', 'sk_test_billing_completion');
});

function billingCompletionCompany(string $customer = 'cus_billing_completion'): array
{
    $plan = Plan::factory()->create();
    $company = Company::factory()->create([
        'current_plan_id' => $plan->getKey(),
        'stripe_id' => $customer,
    ]);
    $user = User::factory()->create([
        'role' => UserRole::Company,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'onboarding_completed_at' => now(),
    ]);
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return [$user, $company, $plan];
}

function stripeInvoicePayload(
    Company $company,
    string $eventId,
    int $eventCreated,
    string $status,
): array {
    return [
        'id' => $eventId,
        'type' => $status === 'paid' ? 'invoice.paid' : 'invoice.updated',
        'created' => $eventCreated,
        'livemode' => false,
        'data' => ['object' => [
            'id' => 'in_billing_completion',
            'customer' => $company->stripe_id,
            'number' => 'ERIN-2026-0042',
            'status' => $status,
            'currency' => 'eur',
            'subtotal' => 10000,
            'total_discount_amounts' => [['amount' => 1000]],
            'total_tax_amounts' => [['amount' => 1710]],
            'total' => 10710,
            'amount_paid' => $status === 'paid' ? 10710 : 0,
            'amount_due' => $status === 'paid' ? 0 : 10710,
            'billing_reason' => 'subscription_cycle',
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_test/in_test',
            'invoice_pdf' => 'https://pay.stripe.com/invoice/acct_test/pdf',
            'discounts' => [['promotion_code' => ['code' => 'ERIN10']]],
            'customer_tax_ids' => [['type' => 'eu_vat', 'value' => 'DE123456789']],
            'customer_address' => [
                'line1' => 'Teststraße 1',
                'postal_code' => '10115',
                'city' => 'Berlin',
                'country' => 'DE',
            ],
            'period_start' => 1785542400,
            'period_end' => 1790812800,
            'created' => 1785542400,
            'status_transitions' => ['paid_at' => $status === 'paid' ? 1785542500 : null],
        ]],
    ];
}

it('stores Stripe invoices idempotently and ignores an older state', function () {
    [$user, $company] = billingCompletionCompany();
    $listener = app(SyncStripeInvoice::class);
    $paid = stripeInvoicePayload($company, 'evt_invoice_paid_new', 200, 'paid');

    $listener->handle(new WebhookReceived($paid));
    $listener->handle(new WebhookReceived($paid));
    $listener->handle(new WebhookReceived(
        stripeInvoicePayload($company, 'evt_invoice_open_old', 100, 'open'),
    ));

    $invoice = CompanyBillingInvoice::query()->sole();
    expect($invoice->status)->toBe('paid')
        ->and($invoice->discount_cents)->toBe(1000)
        ->and($invoice->tax_cents)->toBe(1710)
        ->and($invoice->promotion_codes)->toBe(['ERIN10'])
        ->and($invoice->customer_tax_ids)->toBe([
            ['type' => 'eu_vat', 'value' => 'DE123456789'],
        ]);

    $this->actingAs($user)
        ->get(route('employer.billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employer/Billing')
            ->has('invoices', 1)
            ->where('invoices.0.number', 'ERIN-2026-0042')
            ->where('invoices.0.status', 'paid')
            ->where('invoices.0.tax_cents', 1710));
});

it('does not expose invoices belonging to another company', function () {
    [$user, $company] = billingCompletionCompany('cus_billing_visible');
    [, $foreign] = billingCompletionCompany('cus_billing_foreign');
    app(SyncStripeInvoice::class)->handle(new WebhookReceived(
        stripeInvoicePayload($foreign, 'evt_invoice_foreign', 300, 'paid'),
    ));

    $this->actingAs($user)
        ->get(route('employer.billing'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('invoices', 0)
            ->where('company.id', $company->getKey()));
});

it('keeps price versions immutable with tax and entitlement snapshots', function () {
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_snapshot',
        'stripe_price_id' => 'price_snapshot_v1',
        'price_cents' => 10000,
        'term_months' => 2,
        'seat_limit' => 1,
        'features' => ['billing' => [
            'tax_behavior' => 'exclusive',
            'tax_code' => 'txcd_10103001',
        ]],
    ]);
    $registry = app(PlanStripePriceRegistry::class);
    $first = $registry->record($plan, 'test');

    $plan->update([
        'stripe_price_id' => 'price_snapshot_v2',
        'price_cents' => 12000,
        'seat_limit' => 5,
    ]);
    $second = $registry->record($plan->refresh(), 'test');

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($first->refresh()->is_current)->toBeFalse()
        ->and($first->plan_snapshot['seat_limit'])->toBe(1)
        ->and($first->tax_behavior)->toBe('exclusive')
        ->and($first->tax_code)->toBe('txcd_10103001')
        ->and($second->plan_snapshot['seat_limit'])->toBe(5);

    expect(fn () => $first->forceFill(['price_cents' => 999])->save())
        ->toThrow(LogicException::class);
});

it('revokes a fully refunded Visa purchase even when the refund arrives first', function () {
    [, $company] = billingCompletionCompany();
    $credits = 5;
    $priceId = 'price_visa_completion';
    $metadata = [
        'purchase_type' => 'visa_credits',
        'company_id' => (string) $company->getKey(),
        'credits' => (string) $credits,
        'price_id' => $priceId,
        'erin_signature_version' => StripePurchaseSignature::VERSION,
        'erin_purchase_signature' => app(StripePurchaseSignature::class)->sign(
            (int) $company->getKey(),
            $credits,
            $priceId,
        ),
    ];
    $refund = [
        'id' => 'evt_refund_before_purchase',
        'type' => 'charge.refunded',
        'livemode' => false,
        'data' => ['object' => [
            'id' => 'ch_refund_before_purchase',
            'payment_intent' => 'pi_refund_before_purchase',
            'refunded' => true,
            'metadata' => $metadata,
        ]],
    ];
    $purchase = [
        'id' => 'evt_purchase_after_refund',
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'data' => ['object' => [
            'id' => 'cs_purchase_after_refund',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_refund_before_purchase',
            'metadata' => $metadata,
        ]],
    ];

    app(SyncStripeRefund::class)->handle(new WebhookReceived($refund));
    app(SyncStripeRefund::class)->handle(new WebhookReceived($refund));
    app(SyncStripePurchase::class)->handle(new WebhookReceived($purchase));

    expect(EntitlementLedger::query()->sum('amount'))->toBe(0)
        ->and(EntitlementLedger::query()->where('source', 'stripe_refund')->count())->toBe(1)
        ->and(app(EntitlementService::class)->summary($company)['visa_credits']['purchased'])->toBe(0);
});
