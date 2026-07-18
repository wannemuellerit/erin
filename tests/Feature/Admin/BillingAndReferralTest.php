<?php

use App\Enums\ReferralStatus;
use App\Enums\UserRole;
use App\Models\ActivityEntry;
use App\Models\AuditLog;
use App\Models\BillingChangeIntent;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\User;
use App\Services\Billing\BillingPlanChangeManager;
use App\Services\Platform\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     admin: User,
 *     company: Company,
 *     from: Plan,
 *     to: Plan,
 *     intent: BillingChangeIntent
 * }
 */
function erinAdminManualReviewFixture(): array
{
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $from = Plan::factory()->create([
        'stripe_product_id' => 'prod_admin_manual_from',
        'stripe_price_id' => 'price_admin_manual_from',
    ]);
    $to = Plan::factory()->create([
        'stripe_product_id' => 'prod_admin_manual_to',
        'stripe_price_id' => 'price_admin_manual_to',
    ]);
    $company = Company::factory()->create([
        'name' => 'Prüffall GmbH',
        'current_plan_id' => $from->getKey(),
        'pending_plan_id' => null,
        'stripe_subscription_id' => 'sub_admin_manual',
    ]);
    $intent = BillingChangeIntent::query()->create([
        'public_id' => fake()->uuid(),
        'company_id' => $company->getKey(),
        'from_plan_id' => $from->getKey(),
        'to_plan_id' => $to->getKey(),
        'change_type' => 'downgrade',
        'status' => 'manual_review',
        'active_company_key' => 'company:'.$company->getKey(),
        'stripe_subscription_id' => 'sub_admin_manual',
        'from_stripe_price_id' => 'price_admin_manual_from',
        'to_stripe_price_id' => 'price_admin_manual_to',
        'stripe_idempotency_key' => 'erin-admin-manual-review',
        'context' => ['remote_operations' => []],
        'attempts' => 3,
        'last_error' => 'Manuelle Prüfung erforderlich.',
        'effective_at' => now()->addMonth(),
    ]);

    return compact('admin', 'company', 'from', 'to', 'intent');
}

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

it('shows terminal billing changes with their affected company for manual review', function () {
    [
        'admin' => $admin,
        'intent' => $intent,
    ] = erinAdminManualReviewFixture();

    $this->actingAs($admin)
        ->get(route('admin.billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Billing')
            ->where('summary.billing_manual_review', 1)
            ->has('billing_manual_reviews', 1)
            ->where(
                'billing_manual_reviews.0.public_id',
                $intent->public_id,
            )
            ->where(
                'billing_manual_reviews.0.resolve_url',
                route(
                    'admin.billing.manual-reviews.resolve',
                    $intent->public_id,
                ),
            )
            ->where(
                'billing_manual_reviews.0.company_name',
                'Prüffall GmbH',
            )
            ->where(
                'billing_manual_reviews.0.change_type',
                'downgrade',
            )
            ->where('billing_manual_reviews.0.attempts', 3));
});

it('releases a manual billing review only for an audited retry while preserving the company lock and plan state', function () {
    [
        'admin' => $admin,
        'company' => $company,
        'intent' => $intent,
    ] = erinAdminManualReviewFixture();
    $reason = 'Stripe-Schedule und Zahlung wurden vollständig geprüft.';

    $this->actingAs($admin)
        ->patch(route(
            'admin.billing.manual-reviews.resolve',
            $intent->public_id,
        ), [
            'action' => 'retry',
            'reason' => $reason,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $intent->refresh();
    $company->refresh();
    $audit = AuditLog::query()
        ->where('event', 'admin.billing.manual_review.retry_requested')
        ->sole();
    $activity = ActivityEntry::query()
        ->where('event', 'admin.billing.manual_review.retry_requested')
        ->sole();

    expect($intent->status)->toBe('reconcile')
        ->and($intent->active_company_key)->toBe(
            'company:'.$company->getKey(),
        )
        ->and($company->current_plan_id)->toBe($intent->from_plan_id)
        ->and($company->pending_plan_id)->toBeNull()
        ->and($audit->actor_id)->toBe($admin->getKey())
        ->and($audit->auditable_id)->toBe($intent->getKey())
        ->and($audit->metadata['intent_public_id'])->toBe(
            $intent->public_id,
        )
        ->and($audit->metadata['reason'])->toBe($reason)
        ->and($activity->actor_id)->toBe($admin->getKey())
        ->and($activity->subject_id)->toBe($intent->getKey())
        ->and($activity->payload['reason'])->toBe($reason)
        ->and($activity->visibility)->toBe('platform');
});

it('closes a manual billing review terminally without applying or changing any plan', function () {
    [
        'admin' => $admin,
        'company' => $company,
        'intent' => $intent,
    ] = erinAdminManualReviewFixture();
    $reason = 'Der externe Stripe-Zustand wurde verworfen und dokumentiert.';
    $beforeCurrentPlan = $company->current_plan_id;
    $beforePendingPlan = $company->pending_plan_id;

    $this->actingAs($admin)
        ->patch(route(
            'admin.billing.manual-reviews.resolve',
            $intent->public_id,
        ), [
            'action' => 'close',
            'reason' => $reason,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $intent->refresh();
    $company->refresh();

    expect($intent->status)->toBe('closed')
        ->and($intent->active_company_key)->toBeNull()
        ->and($intent->applied_at)->toBeNull()
        ->and($company->current_plan_id)->toBe($beforeCurrentPlan)
        ->and($company->pending_plan_id)->toBe($beforePendingPlan)
        ->and(app(BillingPlanChangeManager::class)
            ->reconcile($intent)
            ->status)->toBe('closed')
        ->and(AuditLog::query()
            ->where('event', 'admin.billing.manual_review.closed')
            ->where('actor_id', $admin->getKey())
            ->count())->toBe(1)
        ->and(ActivityEntry::query()
            ->where('event', 'admin.billing.manual_review.closed')
            ->where('actor_id', $admin->getKey())
            ->count())->toBe(1);
});

it('forbids support staff from resolving billing manual reviews', function () {
    ['intent' => $intent] = erinAdminManualReviewFixture();
    $support = User::factory()->create(['role' => UserRole::Support]);

    $this->actingAs($support)
        ->patch(route(
            'admin.billing.manual-reviews.resolve',
            $intent->public_id,
        ), [
            'action' => 'close',
            'reason' => 'Nicht autorisierte Support-Entscheidung.',
        ])
        ->assertForbidden();

    expect($intent->refresh()->status)->toBe('manual_review')
        ->and(AuditLog::query()
            ->where('event', 'like', 'admin.billing.manual_review.%')
            ->count())->toBe(0)
        ->and(ActivityEntry::query()
            ->where('event', 'like', 'admin.billing.manual_review.%')
            ->count())->toBe(0);
});

it('rejects incomplete or ambiguous billing manual-review resolutions', function (
    array $payload,
    string $error,
) {
    [
        'admin' => $admin,
        'intent' => $intent,
    ] = erinAdminManualReviewFixture();

    $this->actingAs($admin)
        ->patch(route(
            'admin.billing.manual-reviews.resolve',
            $intent->public_id,
        ), $payload)
        ->assertSessionHasErrors($error);

    expect($intent->refresh()->status)->toBe('manual_review')
        ->and($intent->active_company_key)->not->toBeNull()
        ->and(AuditLog::query()
            ->where('event', 'like', 'admin.billing.manual_review.%')
            ->count())->toBe(0);
})->with([
    'Begründung fehlt' => [
        ['action' => 'retry'],
        'reason',
    ],
    'Aktion ist ungültig' => [[
        'action' => 'apply',
        'reason' => 'Dieser Zustand darf niemals direkt angewandt werden.',
    ], 'action'],
]);

it('rejects double resolution and concurrent manual-review decisions atomically', function () {
    [
        'admin' => $admin,
        'company' => $company,
        'intent' => $intent,
    ] = erinAdminManualReviewFixture();
    $url = route(
        'admin.billing.manual-reviews.resolve',
        $intent->public_id,
    );
    $payload = [
        'action' => 'close',
        'reason' => 'Stripe wurde geprüft und der Vorgang sicher geschlossen.',
    ];
    $lock = Cache::lock(
        'stripe-billing-change-company:'.$company->getKey(),
        120,
    );
    expect($lock->get())->toBeTrue();
    try {
        $this->actingAs($admin)
            ->patch($url, $payload)
            ->assertSessionHasErrors('action');
    } finally {
        $lock->release();
    }
    expect($intent->refresh()->status)->toBe('manual_review')
        ->and(AuditLog::query()
            ->where('event', 'like', 'admin.billing.manual_review.%')
            ->count())->toBe(0);

    $this->actingAs($admin)
        ->patch($url, $payload)
        ->assertRedirect()
        ->assertSessionHas('success');
    $this->actingAs($admin)
        ->patch($url, $payload)
        ->assertSessionHasErrors('action');

    expect($intent->refresh()->status)->toBe('closed')
        ->and(AuditLog::query()
            ->where('event', 'admin.billing.manual_review.closed')
            ->count())->toBe(1)
        ->and(ActivityEntry::query()
            ->where('event', 'admin.billing.manual_review.closed')
            ->count())->toBe(1);
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
            'payout_reference' => 'BANK-2026-0001',
        ])
        ->assertRedirect();

    expect($referral->refresh()->status)->toBe(ReferralStatus::Paid)
        ->and($referral->approved_at)->not->toBeNull()
        ->and($referral->paid_at)->not->toBeNull()
        ->and($referral->metadata['payout_reference'])->toBe('BANK-2026-0001');
});

it('snapshots the configured referral commission when creating a personal code', function () {
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);
    app(PlatformSettings::class)->put(
        'referrals.default_commission_cents',
        75000,
        'billing',
    );

    $this->actingAs($candidate)
        ->post(route('referrals.create'))
        ->assertRedirect();

    $code = ReferralCode::query()->where('user_id', $candidate->getKey())->sole();
    expect($code->commission_cents)->toBe(75000)
        ->and($code->currency)->toBe('EUR');
});
