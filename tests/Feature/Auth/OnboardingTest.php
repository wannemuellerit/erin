<?php

use App\Enums\CompanyMemberRole;
use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Language;
use App\Models\Occupation;
use App\Models\Plan;
use App\Models\Skill;
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

it('persists candidate onboarding steps across sessions and rejects skipped steps', function () {
    $user = User::factory()->create([
        'role' => UserRole::Candidate,
        'email_verified_at' => now(),
        'onboarding_completed_at' => null,
        'onboarding_step' => 2,
    ]);
    CandidateProfile::factory()->create([
        'user_id' => $user->getKey(),
        'occupation_id' => null,
    ]);

    $this->actingAs($user)
        ->put(route('onboarding.candidate.step', 3), [])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->put(route('onboarding.candidate.step', 2), [
            'first_name' => 'Marta',
            'last_name' => 'Nowak',
            'current_country_code' => 'pl',
            'current_city' => 'Wrocław',
            'phone' => '+48 123',
            'whatsapp' => '',
        ])
        ->assertRedirect();

    expect($user->refresh()->onboarding_step)->toBe(3)
        ->and($user->onboarding_data['completed_steps'])->toContain(2);

    $this->post('/logout');
    $this->actingAs($user->fresh())
        ->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('onboarding.current_step', 3)
            ->where('candidate_profile.current_country_code', 'PL'));
});

it('persists the selected company plan before billing details and cannot skip it', function () {
    $user = User::factory()->create([
        'role' => UserRole::Company,
        'email_verified_at' => now(),
        'onboarding_completed_at' => null,
        'onboarding_step' => 2,
    ]);
    $company = Company::factory()->create(['current_plan_id' => null]);
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);
    $plan = Plan::query()->where('slug', 'business')->firstOrFail();

    $this->actingAs($user)
        ->put(route('onboarding.company.step', 3), [])
        ->assertUnprocessable();
    $this->actingAs($user)
        ->put(route('onboarding.company.step', 2), ['plan_slug' => $plan->slug])
        ->assertRedirect();

    expect($user->refresh()->onboarding_step)->toBe(3)
        ->and($company->refresh()->current_plan_id)->toBe($plan->getKey());
});

it('does not index a candidate when onboarding finishes without explicit publication', function () {
    $user = User::factory()->create([
        'role' => UserRole::Candidate,
        'email_verified_at' => now(),
        'onboarding_completed_at' => null,
        'onboarding_step' => 7,
    ]);
    $profile = CandidateProfile::factory()->create([
        'user_id' => $user->getKey(),
        'published_at' => null,
    ]);

    $this->actingAs($user)
        ->put(route('onboarding.candidate.step', 7), [
            'availability' => [[
                'weekday' => 1,
                'starts_at' => '08:00',
                'ends_at' => '12:00',
                'timezone' => 'Europe/Berlin',
            ]],
            'publish_profile' => false,
        ])
        ->assertRedirect(route('candidate.profile'));

    expect($user->refresh()->onboarding_completed_at)->not->toBeNull()
        ->and($profile->refresh()->published_at)->toBeNull()
        ->and($profile->shouldBeSearchable())->toBeFalse();
});

it('persists every candidate wizard section and availability without losing data', function () {
    $user = User::factory()->create([
        'role' => UserRole::Candidate,
        'email_verified_at' => now(),
        'onboarding_completed_at' => null,
        'onboarding_step' => 2,
    ]);
    $profile = CandidateProfile::factory()->create([
        'user_id' => $user->getKey(),
        'occupation_id' => null,
        'published_at' => null,
    ]);
    $occupation = Occupation::query()->where('slug', 'elektriker')->firstOrFail();
    $skill = Skill::query()->firstOrFail();
    $language = Language::query()->firstOrFail();

    $this->actingAs($user)->put(route('onboarding.candidate.step', 2), [
        'first_name' => 'Marta',
        'last_name' => 'Nowak',
        'current_country_code' => 'pl',
        'current_city' => 'Poznań',
        'phone' => '+48 123 456',
        'whatsapp' => '+48 123 456',
    ])->assertRedirect();
    $this->actingAs($user)->put(route('onboarding.candidate.step', 3), [
        'occupation_id' => $occupation->getKey(),
        'current_position' => 'Elektrikerin',
        'desired_position' => 'Industrieelektrikerin',
        'experience_years' => 6,
        'summary' => str_repeat('Erfahrung in Industrieanlagen und Schaltschrankbau. ', 3),
        'relocation_ready' => true,
        'requires_visa' => false,
        'has_work_permit' => true,
    ])->assertRedirect();
    $this->actingAs($user)->put(route('onboarding.candidate.step', 4), [
        'experiences' => [[
            'employer' => 'Elektro Polska',
            'position' => 'Elektrikerin',
            'country_code' => 'PL',
            'started_at' => '2020-01-01',
            'ended_at' => null,
            'is_current' => true,
            'description' => 'Anlagenwartung',
        ]],
        'educations' => [[
            'institution' => 'Technikum Poznań',
            'qualification' => 'Technikerin',
            'field' => 'Elektrotechnik',
            'country_code' => 'PL',
            'started_at' => '2015-09-01',
            'completed_at' => '2019-06-30',
        ]],
    ])->assertRedirect();
    $this->actingAs($user)->put(route('onboarding.candidate.step', 5), [
        'skills' => [['id' => $skill->getKey()]],
        'languages' => [['id' => $language->getKey(), 'level' => 'B1']],
    ])->assertRedirect();
    $this->actingAs($user)->put(route('onboarding.candidate.step', 6), [
        'acknowledged_private_uploads' => true,
    ])->assertRedirect();
    $this->actingAs($user)->put(route('onboarding.candidate.step', 7), [
        'availability' => [
            [
                'weekday' => 1,
                'starts_at' => '08:00',
                'ends_at' => '12:00',
                'timezone' => 'Europe/Berlin',
            ],
            [
                'weekday' => 1,
                'starts_at' => '14:00',
                'ends_at' => '16:00',
                'timezone' => 'Europe/Berlin',
            ],
        ],
        'publish_profile' => false,
    ])->assertRedirect(route('candidate.profile'));

    expect($user->refresh()->onboarding_completed_at)->not->toBeNull()
        ->and($user->availabilitySlots()->count())->toBe(2)
        ->and($profile->refresh()->experiences()->count())->toBe(1)
        ->and($profile->educations()->count())->toBe(1)
        ->and($profile->skills()->whereKey($skill)->exists())->toBeTrue()
        ->and($profile->languages()->whereKey($language)->exists())->toBeTrue()
        ->and($profile->published_at)->toBeNull();
});

it('rejects overlapping or reversed onboarding availability slots', function (array $availability) {
    $user = User::factory()->create([
        'role' => UserRole::Candidate,
        'email_verified_at' => now(),
        'onboarding_completed_at' => null,
        'onboarding_step' => 7,
    ]);
    CandidateProfile::factory()->create(['user_id' => $user->getKey()]);

    $this->actingAs($user)
        ->put(route('onboarding.candidate.step', 7), [
            'availability' => $availability,
            'publish_profile' => false,
        ])
        ->assertSessionHasErrors('availability');

    expect($user->refresh()->onboarding_completed_at)->toBeNull()
        ->and($user->availabilitySlots()->count())->toBe(0);
})->with([
    'overlap' => [[
        ['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'timezone' => 'Europe/Berlin'],
        ['weekday' => 1, 'starts_at' => '11:00', 'ends_at' => '14:00', 'timezone' => 'Europe/Berlin'],
    ]],
    'reversed' => [[
        ['weekday' => 2, 'starts_at' => '16:00', 'ends_at' => '14:00', 'timezone' => 'Europe/Berlin'],
    ]],
]);
