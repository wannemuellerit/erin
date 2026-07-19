<?php

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\CandidateSavedSearch;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\JobPosting;
use App\Models\Occupation;
use App\Models\Skill;
use App\Models\TalentList;
use App\Models\User;
use Database\Seeders\DomainCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'collection']);
    $this->seed(DomainCatalogSeeder::class);
});

/**
 * @return array{0: User, 1: Company}
 */
function erinSearchEmployer(CompanyMemberRole $role = CompanyMemberRole::Owner): array
{
    $user = User::factory()->create([
        'role' => UserRole::Company,
        'onboarding_completed_at' => now(),
    ]);
    $company = Company::factory()->create([
        'subscription_status' => 'active',
    ]);
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => $role,
        'accepted_at' => now(),
    ]);

    return [$user, $company];
}

it('paginates all published candidates instead of truncating the result to one hundred', function () {
    [$user, $company] = erinSearchEmployer();
    CandidateProfile::factory()->count(105)->create();
    CandidateProfile::factory()->create(['published_at' => null]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.candidates.index', ['per_page' => 24]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('candidates.total', 105)
            ->where('candidates.per_page', 24)
            ->has('candidates.data', 24));
});

it('combines residence profession skill experience work permit visa and completeness filters', function () {
    [$user, $company] = erinSearchEmployer();
    $occupation = Occupation::query()->where('slug', 'elektriker')->firstOrFail();
    $skill = Skill::query()->where('slug', 'industrie')->firstOrFail();
    $match = CandidateProfile::factory()->create([
        'occupation_id' => $occupation->getKey(),
        'current_country_code' => 'PL',
        'experience_years' => 8,
        'employment_preferences' => ['full_time'],
        'weekly_hours' => 40,
        'relocation_ready' => true,
        'has_work_permit' => true,
        'requires_visa' => false,
        'completeness' => 90,
    ]);
    $match->skills()->attach($skill, ['is_verified' => false]);
    CandidateProfile::factory()->create([
        'occupation_id' => $occupation->getKey(),
        'current_country_code' => 'RO',
        'experience_years' => 2,
        'has_work_permit' => false,
        'requires_visa' => true,
        'completeness' => 60,
    ]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.candidates.index', [
            'country' => 'pl',
            'occupation' => $occupation->getKey(),
            'skill' => $skill->getKey(),
            'experience' => 5,
            'employment_type' => 'full_time',
            'weekly_hours' => 35,
            'relocation_ready' => 1,
            'work_permit' => 1,
            'visa' => 'not_required',
            'documents_complete' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('candidates.total', 1)
            ->where('candidates.data.0.id', $match->getKey())
            ->missing('candidates.data.0.first_name')
            ->missing('candidates.data.0.current_city'));
});

it('requires a company-owned job for AI matching and returns versioned explanations', function () {
    [$user, $company] = erinSearchEmployer();
    [, $foreignCompany] = erinSearchEmployer();
    $job = JobPosting::factory()->create(['company_id' => $company->getKey()]);
    $foreignJob = JobPosting::factory()->create(['company_id' => $foreignCompany->getKey()]);
    CandidateProfile::factory()->count(3)->create();

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.candidates.index', ['view' => 'ai']))
        ->assertSessionHasErrors('job');

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.candidates.index', ['view' => 'ai', 'job' => $foreignJob->getKey()]))
        ->assertNotFound();

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.candidates.index', ['view' => 'ai', 'job' => $job->getKey()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('candidates.data', 3)
            ->where('candidates.data.0.match.version', '1.0')
            ->has('candidates.data.0.match.score')
            ->has('candidates.data.0.match.factors'));
});

it('stores searches and talent lists only inside the active tenant', function () {
    [$user, $company] = erinSearchEmployer();
    [, $foreignCompany] = erinSearchEmployer();
    $foreignList = TalentList::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'created_by' => $foreignCompany->memberships()->value('user_id'),
        'name' => 'Fremd',
    ]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.candidate-saved-searches.store'), [
            'name' => 'Elektriker Polen',
            'filters' => [
                'country' => 'PL',
                'occupation' => Occupation::query()->value('id'),
                'forbidden_identity_field' => 'Marta',
            ],
        ])
        ->assertRedirect();
    expect(CandidateSavedSearch::query()->firstOrFail()->filters)
        ->not->toHaveKey('forbidden_identity_field');

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->patch(route('employer.talent-lists.update', $foreignList), [
            'name' => 'Übernommen',
        ])
        ->assertNotFound();
    expect($foreignList->refresh()->name)->toBe('Fremd');
});

it('creates renames and deletes tenant-owned talent lists and saved searches', function () {
    [$user, $company] = erinSearchEmployer();

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.talent-lists.store'), [
            'name' => 'Elektriker NRW',
            'description' => 'Kandidaten für Nordrhein-Westfalen',
        ])
        ->assertRedirect();

    $list = $company->talentLists()->where('name', 'Elektriker NRW')->firstOrFail();
    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->patch(route('employer.talent-lists.update', $list), [
            'name' => 'Elektriker Rheinland',
            'description' => null,
        ])
        ->assertRedirect();
    expect($list->refresh()->name)->toBe('Elektriker Rheinland');

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.candidate-saved-searches.store'), [
            'name' => 'Verfügbare Elektriker',
            'filters' => ['country' => 'PL', 'work_permit' => true],
        ])
        ->assertRedirect();
    $savedSearch = CandidateSavedSearch::query()
        ->whereBelongsTo($company)
        ->firstOrFail();

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->delete(route('employer.candidate-saved-searches.destroy', $savedSearch))
        ->assertRedirect();
    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->delete(route('employer.talent-lists.destroy', $list))
        ->assertRedirect();

    expect(CandidateSavedSearch::query()->count())->toBe(0)
        ->and(TalentList::query()->whereKey($list->getKey())->exists())->toBeFalse();
});

it('keeps default talent lists undeletable', function () {
    [$user, $company] = erinSearchEmployer();
    $list = TalentList::query()->create([
        'company_id' => $company->getKey(),
        'created_by' => $user->getKey(),
        'name' => 'Favoriten',
        'is_default' => true,
    ]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->delete(route('employer.talent-lists.destroy', $list))
        ->assertUnprocessable();

    expect($list->refresh()->exists)->toBeTrue();
});
