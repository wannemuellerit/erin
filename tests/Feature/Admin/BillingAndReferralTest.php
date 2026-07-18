<?php

use App\Enums\ReferralStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows only a secret-free Stripe configuration status', function () {
    config()->set('cashier.key', 'pk_live_never_render');
    config()->set('cashier.secret', 'sk_live_never_render');
    config()->set('cashier.webhook.secret', 'whsec_never_render');
    config()->set('services.stripe.seat_price_id', 'price_seat_never_render');
    config()->set('services.stripe.visa_price_id');
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    Plan::factory()->create([
        'slug' => 'configured',
        'name' => 'Konfiguriert',
        'stripe_product_id' => 'prod_never_render',
        'stripe_price_id' => 'price_never_render',
    ]);
    Plan::factory()->create([
        'slug' => 'missing',
        'name' => 'Unvollständig',
        'stripe_product_id' => null,
        'stripe_price_id' => null,
    ]);
    Plan::factory()->create([
        'slug' => 'enterprise-status',
        'is_enterprise' => true,
        'stripe_product_id' => null,
        'stripe_price_id' => null,
    ]);

    $response = $this->actingAs($admin)->get(route('admin.billing.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Billing')
            ->where('stripe_configuration.mode', 'live')
            ->where('stripe_configuration.publishable_key', true)
            ->where('stripe_configuration.secret_key', true)
            ->where('stripe_configuration.webhook_secret', true)
            ->where('stripe_configuration.seat_price', true)
            ->where('stripe_configuration.visa_price', false)
            ->where('stripe_configuration.launch_prices_configured', 1)
            ->where('stripe_configuration.launch_prices_total', 2)
            ->where('stripe_configuration.ready', false)
            ->has('stripe_configuration.plans', 2))
        ->assertDontSee('sk_live_never_render')
        ->assertDontSee('whsec_never_render');
});

it('requires a new Stripe Price ID when a configured package price changes', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_basic',
        'stripe_price_id' => 'price_basic_v1',
        'price_cents' => 299900,
    ]);
    $payload = [
        'name' => $plan->name,
        'description' => $plan->description,
        'price_cents' => 309900,
        'currency' => 'EUR',
        'term_months' => 2,
        'active_jobs_limit' => 1,
        'seat_limit' => 1,
        'ai_credits_monthly' => 0,
        'job_boosts_per_term' => 0,
        'visa_credits_per_term' => 0,
        'is_active' => true,
        'stripe_product_id' => 'prod_basic',
        'stripe_price_id' => 'price_basic_v1',
        'features' => [],
    ];

    $this->actingAs($admin)
        ->patch(route('admin.billing.plans.update', $plan), $payload)
        ->assertSessionHasErrors('stripe_price_id');

    $payload['stripe_price_id'] = 'price_basic_v2';

    $this->actingAs($admin)
        ->patch(route('admin.billing.plans.update', $plan), $payload)
        ->assertRedirect();

    expect($plan->refresh()->price_cents)->toBe(309900)
        ->and($plan->stripe_price_id)->toBe('price_basic_v2')
        ->and(AuditLog::query()->where('event', 'admin.plan.updated')->exists())->toBeTrue();
});

it('enforces the referral hold period before manual approval and payout', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $referrer = User::factory()->create();
    $code = ReferralCode::query()->create([
        'user_id' => $referrer->id,
        'code' => 'ERIN-REFER',
        'commission_cents' => 50000,
    ]);
    $referral = Referral::query()->create([
        'referral_code_id' => $code->id,
        'status' => ReferralStatus::Holding,
        'hold_until' => now()->addDay(),
        'commission_cents' => 50000,
    ]);

    $this->actingAs($admin)
        ->patch(route('admin.referrals.update', $referral), [
            'status' => ReferralStatus::Approved->value,
        ])
        ->assertSessionHasErrors('status');

    $referral->update(['hold_until' => now()->subMinute()]);

    $this->actingAs($admin)
        ->patch(route('admin.referrals.update', $referral), [
            'status' => ReferralStatus::Approved->value,
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->patch(route('admin.referrals.update', $referral), [
            'status' => ReferralStatus::Paid->value,
        ])
        ->assertRedirect();

    expect($referral->refresh()->status)->toBe(ReferralStatus::Paid)
        ->and($referral->approved_at)->not->toBeNull()
        ->and($referral->paid_at)->not->toBeNull();
});
