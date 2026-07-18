<?php

use App\Enums\CompanyMemberRole;
use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Occupation;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DomainCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DomainCatalogSeeder::class);
});

it('guides a verified candidate through onboarding before marketplace access', function () {
    $user = User::factory()->create([
        'role' => UserRole::Candidate,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'onboarding_completed_at' => null,
    ]);
    $profile = CandidateProfile::factory()->create([
        'user_id' => $user->getKey(),
        'occupation_id' => null,
        'current_country_code' => null,
        'summary' => null,
    ]);
    $occupation = Occupation::query()->where('slug', 'elektriker')->firstOrFail();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('onboarding.show'));

    $this->actingAs($user)
        ->get(route('candidate.jobs'))
        ->assertRedirect(route('onboarding.show'));

    $this->actingAs($user)
        ->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding')
            ->where('role', UserRole::Candidate->value)
            ->has('occupations', 5));

    $response = $this->actingAs($user)->put(route('onboarding.candidate'), [
        'occupation_id' => $occupation->getKey(),
        'current_country_code' => 'pl',
        'current_city' => 'Wrocław',
        'phone' => '+48 555 123 456',
        'summary' => 'Ich bin ausgebildete Elektrikerin mit Erfahrung im Schaltschrankbau und möchte langfristig in Deutschland arbeiten.',
        'desired_position' => 'Elektrikerin',
        'experience_years' => 7,
        'relocation_ready' => true,
        'requires_visa' => false,
        'has_work_permit' => true,
    ]);

    $response->assertRedirect(route('candidate.profile'));
    expect($user->refresh()->onboarding_completed_at)->not->toBeNull()
        ->and($profile->refresh()->occupation_id)->toBe($occupation->getKey())
        ->and($profile->current_country_code)->toBe('PL')
        ->and($profile->completeness)->toBeGreaterThan(0);
});

it('collects package, company and billing data before Stripe checkout access', function () {
    $user = User::factory()->create([
        'role' => UserRole::Company,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'onboarding_completed_at' => null,
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => null,
        'status' => CompanyStatus::Pending,
        'subscription_status' => null,
        'legal_name' => null,
        'postal_code' => null,
        'address_line1' => null,
    ]);
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);
    $plan = Plan::query()->where('slug', 'business')->firstOrFail();

    $this->actingAs($user)
        ->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding')
            ->where('role', UserRole::Company->value)
            ->has('plans', 3));

    $response = $this->actingAs($user)->put(route('onboarding.company'), [
        'plan_slug' => $plan->slug,
        'legal_name' => 'Beispiel Industrie GmbH',
        'email' => 'rechnung@beispiel-industrie.de',
        'website' => 'https://beispiel-industrie.de',
        'industry' => 'Industrie',
        'employee_count' => 120,
        'country_code' => 'de',
        'city' => 'Düsseldorf',
        'postal_code' => '40210',
        'address_line1' => 'Industriestraße 10',
    ]);

    $response->assertRedirect(route('employer.billing'));
    expect($user->refresh()->onboarding_completed_at)->not->toBeNull()
        ->and($company->refresh()->current_plan_id)->toBe($plan->getKey())
        ->and($company->legal_name)->toBe('Beispiel Industrie GmbH')
        ->and($company->country_code)->toBe('DE');

    $this->actingAs($user)
        ->get(route('employer.candidates.index'))
        ->assertRedirect(route('employer.billing'));
});

it('prevents one public persona from completing the other persona onboarding', function () {
    $candidate = User::factory()->create([
        'role' => UserRole::Candidate,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'onboarding_completed_at' => null,
    ]);
    CandidateProfile::factory()->create(['user_id' => $candidate->getKey()]);

    $this->actingAs($candidate)
        ->put(route('onboarding.company'), [])
        ->assertForbidden();
});
