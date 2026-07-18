<?php

use App\Enums\CompanyMemberRole;
use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DomainCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps configured Stripe prices onto the launch packages', function () {
    config()->set('services.stripe.basic_price_id', 'price_basic_configured');
    config()->set('services.stripe.business_price_id', 'price_business_configured');
    config()->set('services.stripe.premium_price_id', 'price_premium_configured');

    $this->seed(DomainCatalogSeeder::class);

    expect(Plan::query()->where('slug', 'basic')->value('stripe_price_id'))->toBe('price_basic_configured')
        ->and(Plan::query()->where('slug', 'business')->value('stripe_price_id'))->toBe('price_business_configured')
        ->and(Plan::query()->where('slug', 'premium')->value('stripe_price_id'))->toBe('price_premium_configured')
        ->and(Plan::query()->where('slug', 'enterprise')->value('stripe_price_id'))->toBeNull()
        ->and(config('cashier.webhook.events'))->toContain('checkout.session.completed');
});

it('does not erase an existing Stripe price when no environment mapping is present', function () {
    $plan = Plan::factory()->create([
        'slug' => 'basic',
        'stripe_price_id' => 'price_existing',
    ]);

    config()->set('services.stripe.basic_price_id');
    $this->seed(DomainCatalogSeeder::class);

    expect($plan->refresh()->stripe_price_id)->toBe('price_existing');
});

it('keeps the success return informational and validates the Checkout session reference', function () {
    $user = User::factory()->create([
        'role' => UserRole::Company,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'onboarding_completed_at' => now(),
    ]);
    $company = Company::factory()->create([
        'status' => CompanyStatus::Pending,
        'subscription_status' => null,
    ]);
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('employer.billing.success'))
        ->assertSessionHasErrors('session_id');

    $this->actingAs($user)
        ->get(route('employer.billing.success', ['session_id' => 'cs_test_ABC123']))
        ->assertRedirect(route('employer.billing'))
        ->assertSessionHas('success');

    expect($company->refresh()->status)->toBe(CompanyStatus::Pending)
        ->and($company->subscription_status)->toBeNull();
});
