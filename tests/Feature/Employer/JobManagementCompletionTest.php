<?php

use App\Enums\CompanyMemberRole;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\CompanyMembership;
use App\Models\CompanyUsagePeriod;
use App\Models\JobMedia;
use App\Models\JobPosting;
use App\Models\JobTemplate;
use App\Models\Language;
use App\Models\Occupation;
use App\Models\Plan;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\DomainCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Company, 2: Plan}
 */
function erinJobManager(array $planOverrides = []): array
{
    $plan = Plan::factory()->create([
        'active_jobs_limit' => 10,
        'job_boosts_per_term' => 2,
        ...$planOverrides,
    ]);
    $company = Company::factory()->create([
        'current_plan_id' => $plan->getKey(),
        'subscription_started_at' => now()->startOfDay(),
        'subscription_renews_at' => now()->startOfDay()->addMonths(2),
    ]);
    $user = User::factory()->create([
        'role' => UserRole::Company,
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

function erinJobPayload(array $overrides = []): array
{
    (new DomainCatalogSeeder)->run();

    return [
        'title' => 'Elektroniker Betriebstechnik',
        'position' => 'Elektroniker',
        'description' => str_repeat('Eine konkrete und ausführliche Stellenbeschreibung. ', 3),
        'summary' => str_repeat('Arbeiten in einem erfahrenen und hilfsbereiten Team. ', 2),
        'responsibilities' => str_repeat('Installation, Wartung und dokumentierte Fehlersuche. ', 2),
        'requirements' => str_repeat('Abgeschlossene Ausbildung und sorgfältige Arbeitsweise. ', 2),
        'occupation_id' => Occupation::query()->firstOrFail()->id,
        'expected_experience_years' => 2,
        'vacancies' => 1,
        'hours_min' => 35,
        'hours_max' => 40,
        'compensation_min_cents' => 3600000,
        'target_country_code' => 'DE',
        'employment_type' => 'full_time',
        'currency' => 'EUR',
        'compensation_interval' => 'year',
        'is_remote' => true,
        'visa_package_available' => false,
        'skills' => [['id' => Skill::query()->firstOrFail()->id, 'importance' => 3]],
        'languages' => [['id' => Language::query()->firstOrFail()->id, 'minimum_level' => 'B1', 'is_required' => true]],
        'screening_questions' => [],
        'translations' => [
            'de' => [
                'title' => 'Elektroniker Betriebstechnik',
                'position' => 'Elektroniker',
                'description' => 'Eine konkrete und ausführliche Stellenbeschreibung.',
            ],
            'en' => [
                'title' => '',
                'position' => '',
                'description' => '',
            ],
        ],
        ...$overrides,
    ];
}

it('only exposes plan and tenant eligible templates', function () {
    [$user, $company, $plan] = erinJobManager();
    $global = JobTemplate::query()->create([
        'name' => 'Standard',
        'content' => ['title' => 'Standardstelle'],
    ]);
    $premium = JobTemplate::query()->create([
        'plan_id' => $plan->getKey(),
        'name' => 'Premium',
        'content' => ['title' => 'Premiumstelle'],
        'is_premium' => true,
    ]);
    $tenant = JobTemplate::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Eigene Vorlage',
        'content' => ['title' => 'Eigene Stelle'],
    ]);
    $foreign = JobTemplate::query()->create([
        'company_id' => Company::factory()->create()->getKey(),
        'name' => 'Fremd',
        'content' => ['title' => 'Nicht sichtbar'],
    ]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.jobs.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('templates', 3)
            ->where('templates', fn ($templates) => collect($templates)
                ->pluck('id')
                ->sort()
                ->values()
                ->all() === collect([$global, $premium, $tenant])
                ->pluck('id')
                ->sort()
                ->values()
                ->all()
                && collect($templates)->doesntContain('id', $foreign->getKey())));
});

it('persists localized content and complete screening question semantics', function () {
    [$user, $company] = erinJobManager();

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.jobs.store'), erinJobPayload([
            'translations' => [
                'en' => [
                    'title' => 'Industrial electrician',
                    'position' => 'Electrician',
                    'description' => 'A concrete job description in English.',
                ],
            ],
            'screening_questions' => [[
                'question' => 'Welche Schicht passt?',
                'type' => 'choice',
                'is_required' => true,
                'options' => ['Frühschicht', 'Spätschicht'],
            ]],
        ]))
        ->assertRedirect();

    $job = JobPosting::query()->sole();
    expect($job->translations()->pluck('title', 'locale')->all())
        ->toBe([
            'de' => 'Elektroniker Betriebstechnik',
            'en' => 'Industrial electrician',
        ])
        ->and($job->screeningQuestions()->sole()->only(['type', 'is_required', 'options']))
        ->toBe([
            'type' => 'choice',
            'is_required' => true,
            'options' => ['Frühschicht', 'Spätschicht'],
        ]);
});

it('rejects incomplete choices foreign locations and unavailable templates', function () {
    [$user, $company] = erinJobManager();
    $foreignCompany = Company::factory()->create();
    $foreignLocation = CompanyLocation::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'name' => 'Fremdstandort',
        'country_code' => 'DE',
        'city' => 'Berlin',
    ]);
    $foreignTemplate = JobTemplate::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'name' => 'Fremd',
        'content' => ['title' => 'Fremd'],
    ]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.jobs.store'), erinJobPayload([
            'location_id' => $foreignLocation->getKey(),
            'screening_questions' => [[
                'question' => 'Auswahl?',
                'type' => 'choice',
                'is_required' => true,
                'options' => ['Nur eine'],
            ]],
        ]))
        ->assertSessionHasErrors([
            'location_id',
            'screening_questions.0.options',
        ]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.jobs.store'), erinJobPayload([
            'template_id' => $foreignTemplate->getKey(),
        ]))
        ->assertForbidden();

    expect(JobPosting::query()->count())->toBe(0);
});

it('prevents a second active boost without consuming another allowance', function () {
    [$user, $company] = erinJobManager();
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $user->getKey(),
        'status' => JobStatus::Published,
        'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.jobs.boost', $job))
        ->assertSessionDoesntHaveErrors();
    $firstExpiry = $job->refresh()->boosted_until;

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->post(route('employer.jobs.boost', $job))
        ->assertSessionHasErrors('boost');

    expect(CompanyUsagePeriod::query()->sole()->job_boosts_used)->toBe(1)
        ->and($job->refresh()->boosted_until?->equalTo($firstExpiry))->toBeTrue();
});

it('deletes owned private media but never media from another tenant', function () {
    Storage::fake('private');
    [$user, $company] = erinJobManager();
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $user->getKey(),
    ]);
    $path = "companies/{$company->getKey()}/jobs/{$job->getKey()}/brief.pdf";
    Storage::disk('private')->put($path, 'brief');
    $media = JobMedia::query()->create([
        'job_posting_id' => $job->getKey(),
        'uploaded_by' => $user->getKey(),
        'type' => 'document',
        'disk' => 'private',
        'path' => $path,
        'original_name' => 'brief.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 5,
        'scan_result' => 'clean',
    ]);
    $foreignMedia = JobMedia::query()->create([
        'job_posting_id' => JobPosting::factory()->create()->getKey(),
        'type' => 'document',
        'disk' => 'private',
        'path' => 'foreign.pdf',
        'original_name' => 'foreign.pdf',
        'scan_result' => 'clean',
    ]);
    Storage::disk('private')->put('foreign.pdf', 'foreign');

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->delete(route('employer.jobs.media.destroy', [$foreignMedia->job_posting_id, $foreignMedia]))
        ->assertNotFound();
    Storage::disk('private')->assertExists('foreign.pdf');

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->delete(route('employer.jobs.media.destroy', [$media->job_posting_id, $media]))
        ->assertRedirect();
    Storage::disk('private')->assertMissing($path);
    $this->assertDatabaseMissing('job_media', ['id' => $media->getKey()]);
});

it('presents and searches translated jobs in the active locale', function () {
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);
    CandidateProfile::factory()->create([
        'user_id' => $candidate->getKey(),
        'published_at' => now(),
        'completeness' => 100,
    ]);
    $job = JobPosting::factory()->create([
        'title' => 'Elektroniker',
        'position' => 'Elektroniker',
        'description' => 'Deutsche Beschreibung',
    ]);
    $job->translations()->create([
        'locale' => 'en',
        'title' => 'Industrial electrician',
        'position' => 'Electrician',
        'description' => 'English description',
    ]);

    $this->actingAs($candidate)
        ->withSession(['locale' => 'en'])
        ->get(route('candidate.jobs', ['search' => 'Industrial electrician']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.id', $job->getKey())
            ->where('jobs.0.title', 'Industrial electrician')
            ->where('jobs.0.description', 'English description'));
});
