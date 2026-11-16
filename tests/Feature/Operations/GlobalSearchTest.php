<?php

use App\Enums\CompanyMemberRole;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires authentication and at least two search characters', function () {
    $this->getJson(route('search', ['q' => 'job']))->assertUnauthorized();
    $user = User::factory()->create(['role' => UserRole::Candidate]);
    $this->actingAs($user)->getJson(route('search', ['q' => 'x']))
        ->assertUnprocessable()->assertJsonValidationErrors('q');
});

it('returns only published marketplace records to candidates', function () {
    $candidate = CandidateProfile::factory()->create()->user;
    $company = Company::factory()->create();
    $published = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'title' => 'Sicherheitsingenieur',
        'status' => JobStatus::Published,
        'published_at' => now(),
    ]);
    JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'title' => 'Sicherheitsingenieur intern',
        'status' => JobStatus::Draft,
        'published_at' => null,
    ]);

    $response = $this->actingAs($candidate)
        ->getJson(route('search', ['q' => 'Sicherheitsingenieur']))
        ->assertOk();

    $response->assertJsonCount(1, 'groups.jobs')
        ->assertJsonPath('groups.jobs.0.label', $published->title);
});

it('isolates company search results and never exposes candidate identity', function () {
    $owner = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();
    $foreign = Company::factory()->create();
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $owner->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);
    $ownJob = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
        'title' => 'Elektriker Berlin',
    ]);
    JobPosting::factory()->create([
        'company_id' => $foreign->getKey(),
        'title' => 'Elektriker Fremdmandant',
    ]);
    CandidateProfile::factory()->create([
        'first_name' => 'Private',
        'last_name' => 'Person',
        'desired_position' => 'Elektriker',
        'current_country_code' => 'PL',
        'published_at' => now(),
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->getJson(route('search', ['q' => 'Elektriker']))
        ->assertOk();

    $response->assertJsonCount(1, 'groups.jobs')
        ->assertJsonPath('groups.jobs.0.label', $ownJob->title)
        ->assertJsonPath('groups.candidates.0.label', 'Elektriker · PL');
    expect($response->getContent())->not->toContain('Private', 'Person', 'Fremdmandant');
});

it('uses an explicit role matrix for partner and platform staff searches', function () {
    $partner = User::factory()->create(['role' => UserRole::Partner]);
    $support = User::factory()->create(['role' => UserRole::Support]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $company = Company::factory()->create(['name' => 'E2E Suchfirma GmbH']);
    User::factory()->create([
        'name' => 'E2E Suchnutzer',
        'email' => 'search-user@example.test',
    ]);

    $this->actingAs($partner)->getJson(route('search', ['q' => 'E2E']))
        ->assertOk()->assertExactJson([
            'query' => 'E2E',
            'minimum_characters' => 2,
            'groups' => [],
        ]);

    foreach ([$support, $admin] as $staff) {
        $response = $this->actingAs($staff)
            ->getJson(route('search', ['q' => 'E2E']))
            ->assertOk();
        $response->assertJsonPath('groups.companies.0.label', $company->name)
            ->assertJsonPath('groups.users.0.label', 'E2E Suchnutzer');
    }
});
