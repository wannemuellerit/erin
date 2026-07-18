<?php

use App\Enums\ApplicationStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiConsent;
use App\Models\AiRun;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('seeds the immutable launch catalog and verified demo identities', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Plan::query()->pluck('slug')->sort()->values()->all())
        ->toBe(['basic', 'business', 'enterprise', 'premium'])
        ->and(Plan::query()->where('slug', 'basic')->value('price_cents'))->toBe(299_900)
        ->and(Plan::query()->where('slug', 'enterprise')->value('seat_limit'))->toBeNull()
        ->and(User::query()->count())->toBe(0);

    config()->set('app.demo_mode', true);
    $this->seed(DemoDataSeeder::class);

    $admin = User::query()->where('email', 'admin@wannemueller.dev')->firstOrFail();

    expect($admin->role)->toBe(UserRole::SuperAdmin)
        ->and($admin->status)->toBe(UserStatus::Active)
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and($admin->isSuperAdmin())->toBeTrue()
        ->and(CandidateProfile::query()->count())->toBe(10)
        ->and(Company::query()->count())->toBe(2)
        ->and(User::query()->where('role', UserRole::Company)->count())->toBe(2);

    $candidate = CandidateProfile::query()->firstOrFail();
    $application = JobApplication::query()->firstOrFail();

    expect($candidate->canApply())->toBeTrue()
        ->and($candidate->skills)->not->toBeEmpty()
        ->and($application->pipelineStage())->toBe('interesting')
        ->and($application->jobPosting->company->memberships)->not->toBeEmpty();
});

it('enforces tenant membership and keeps support access read only', function () {
    $owner = User::factory()->create(['role' => UserRole::Company]);
    $support = User::factory()->create(['role' => UserRole::Support]);
    $stranger = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();

    CompanyMembership::query()->create([
        'company_id' => $company->id,
        'user_id' => $owner->id,
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    $candidate = CandidateProfile::factory()->create();
    $job = JobPosting::factory()->create([
        'company_id' => $company->id,
        'created_by' => $owner->id,
    ]);
    $application = JobApplication::factory()->create([
        'job_posting_id' => $job->id,
        'candidate_profile_id' => $candidate->id,
        'status' => ApplicationStatus::InReview,
    ]);

    expect($owner->belongsToCompany($company))->toBeTrue()
        ->and($stranger->belongsToCompany($company))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('manage', $application))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('view', $application))->toBeFalse()
        ->and(Gate::forUser($support)->allows('view', $application))->toBeTrue()
        ->and(Gate::forUser($support)->allows('manage', $application))->toBeFalse();
});

it('keeps company viewers read only across recruiting actions', function () {
    $viewer = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $viewer->getKey(),
        'role' => CompanyMemberRole::Viewer,
        'accepted_at' => now(),
    ]);
    $candidate = CandidateProfile::factory()->create();
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $viewer->getKey(),
    ]);

    $this->actingAs($viewer)
        ->get(route('employer.candidates.index'))
        ->assertOk();

    $this->actingAs($viewer)
        ->post(route('employer.candidates.invite', $candidate), [
            'job_posting_id' => $job->getKey(),
            'message' => 'Bitte bewerben.',
        ])
        ->assertForbidden();
});

it('requires an active consent for sensitive AI runs', function () {
    $user = User::factory()->create();
    $consent = AiConsent::query()->create([
        'user_id' => $user->id,
        'purpose' => 'candidate_document_analysis',
        'version' => '1.0',
        'granted_at' => now(),
        'data_categories' => ['cv'],
    ]);

    $run = AiRun::query()->create([
        'user_id' => $user->id,
        'consent_id' => $consent->id,
        'purpose' => 'candidate_document_analysis',
        'provider' => 'openai',
        'model' => 'configured-model',
        'prompt_version' => '1.0',
        'requires_consent' => true,
    ]);

    expect($run->load('consent')->canRun())->toBeTrue();

    $consent->update(['withdrawn_at' => now()]);

    expect($run->refresh()->load('consent')->canRun())->toBeFalse();
});
