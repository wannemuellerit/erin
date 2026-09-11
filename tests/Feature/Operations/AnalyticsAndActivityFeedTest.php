<?php

use App\Enums\ApplicationStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\InterviewStatus;
use App\Enums\UserRole;
use App\Models\ActivityEntry;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\Activity\ActivityRecorder;
use App\Services\Analytics\ActivityEventBackfill;
use App\Services\Analytics\RecruitingAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, company: Company}
 */
function erinAnalyticsEmployer(): array
{
    $user = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();

    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return compact('user', 'company');
}

it('returns explicit zero values and a null hiring duration without applications', function () {
    ['user' => $owner, 'company' => $company] = erinAnalyticsEmployer();
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
        'title' => 'Pflegefachkraft',
    ]);
    $from = Carbon::parse('2026-07-01');
    $to = Carbon::parse('2026-07-07');

    $analytics = app(RecruitingAnalyticsService::class)->forCompany($company, $from, $to);

    expect($analytics['summary'])->toBe([
        'applications' => 0,
        'interviews' => 0,
        'hires' => 0,
        'interview_rate' => 0,
        'hire_rate' => 0,
        'average_days_to_hire' => null,
    ])->and($analytics['jobs'])->toBe([[
        'id' => $job->getKey(),
        'title' => 'Pflegefachkraft',
        'status' => 'published',
        'applications' => 0,
        'interviews' => 0,
        'hires' => 0,
        'interview_rate' => 0,
        'hire_rate' => 0,
    ]])->and($analytics['countries'])->toBe([])
        ->and($analytics['timeline'])->toHaveCount(7);
});

it('isolates recruiting analytics by active company', function () {
    Carbon::setTestNow('2026-07-18 12:00:00');
    ['user' => $owner, 'company' => $company] = erinAnalyticsEmployer();
    ['user' => $foreignOwner, 'company' => $foreignCompany] = erinAnalyticsEmployer();
    $candidate = CandidateProfile::factory()->create(['current_country_code' => 'PL']);
    $foreignCandidate = CandidateProfile::factory()->create(['current_country_code' => 'RO']);
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
        'title' => 'Elektriker/in',
    ]);
    $foreignJob = JobPosting::factory()->create([
        'company_id' => $foreignCompany->getKey(),
        'created_by' => $foreignOwner->getKey(),
        'title' => 'Fremde Stelle',
    ]);
    $application = JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $candidate->getKey(),
        'status' => ApplicationStatus::Hired,
        'applied_at' => now()->subDays(4),
        'decided_at' => now(),
    ]);
    Interview::query()->create([
        'application_id' => $application->getKey(),
        'organizer_id' => $owner->getKey(),
        'proposed_by' => $owner->getKey(),
        'status' => InterviewStatus::Completed,
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDays(2)->addHour(),
        'timezone' => 'Europe/Berlin',
    ]);
    JobApplication::factory()->count(2)->create([
        'job_posting_id' => $foreignJob->getKey(),
        'candidate_profile_id' => fn () => CandidateProfile::factory()->create([
            'current_country_code' => 'RO',
        ])->getKey(),
        'applied_at' => now()->subDay(),
    ]);

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.analytics', [
            'from' => '2026-07-01',
            'to' => '2026-07-18',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employer/Analytics')
            ->where('analytics.summary.applications', 1)
            ->where('analytics.summary.interviews', 1)
            ->where('analytics.summary.hires', 1)
            ->where('analytics.summary.interview_rate', 100)
            ->where('analytics.summary.hire_rate', 100)
            ->where('analytics.summary.average_days_to_hire', 4)
            ->has('analytics.jobs', 1)
            ->where('analytics.jobs.0.id', $job->getKey())
            ->has('analytics.countries', 1)
            ->where('analytics.countries.0.country', 'PL')
            ->where('analytics.countries.0.applications', 1));

    Carbon::setTestNow();
});

it('keeps live company activity private from platform staff outside an impersonated member session', function () {
    ['user' => $owner, 'company' => $company] = erinAnalyticsEmployer();
    $support = User::factory()->create(['role' => UserRole::Support]);
    $channel = Broadcast::getChannels()['company.{companyId}'];

    expect($channel($owner, $company->getKey()))->toBeTrue()
        ->and($channel($support, $company->getKey()))->toBeFalse();
});

it('versions and deduplicates recruiting events without accepting private payload fields', function () {
    ['user' => $owner, 'company' => $company] = erinAnalyticsEmployer();
    $recorder = app(ActivityRecorder::class);

    $first = $recorder->record(
        'application.status_changed',
        $owner,
        $company,
        payload: ['status' => 'interview_scheduled'],
        idempotencyKey: 'application:42:interview_scheduled',
        schemaVersion: 2,
    );
    $duplicate = $recorder->record(
        'application.status_changed',
        $owner,
        $company,
        payload: ['status' => 'interview_scheduled'],
        idempotencyKey: 'application:42:interview_scheduled',
        schemaVersion: 2,
    );

    expect($duplicate->getKey())->toBe($first->getKey())
        ->and($first->event_uuid)->not->toBeNull()
        ->and($first->schema_version)->toBe(2)
        ->and(ActivityEntry::query()->count())->toBe(1)
        ->and(fn () => $recorder->record(
            'document.reviewed',
            $owner,
            $company,
            payload: ['document_name' => 'passport.pdf'],
        ))->toThrow(InvalidArgumentException::class);
});

it('backfills historical activity identifiers once and marks their data quality', function () {
    ['user' => $owner, 'company' => $company] = erinAnalyticsEmployer();
    $id = DB::table('activity_entries')->insertGetId([
        'company_id' => $company->getKey(),
        'actor_id' => $owner->getKey(),
        'event_uuid' => null,
        'event' => 'application.status_changed',
        'schema_version' => 0,
        'data_quality' => 'observed',
        'visibility' => 'company',
        'occurred_at' => now()->subYear(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $backfill = app(ActivityEventBackfill::class);

    expect($backfill->run($id))->toBe(1)
        ->and($backfill->run($id))->toBe(0);

    $entry = ActivityEntry::query()->findOrFail($id);
    expect($entry->event_uuid)->toBeString()->not->toBeEmpty()
        ->and($entry->schema_version)->toBe(1)
        ->and($entry->data_quality)->toBe('backfilled');
});

it('exports only tenant-scoped job aggregates and audits the selected period', function () {
    ['user' => $owner, 'company' => $company] = erinAnalyticsEmployer();
    ['user' => $foreignOwner, 'company' => $foreignCompany] = erinAnalyticsEmployer();
    JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
        'title' => '=Sensitive Formula',
    ]);
    JobPosting::factory()->create([
        'company_id' => $foreignCompany->getKey(),
        'created_by' => $foreignOwner->getKey(),
        'title' => 'Foreign Secret',
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.analytics.export', [
            'from' => now()->subWeek()->toDateString(),
            'to' => now()->toDateString(),
        ]))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($response->streamedContent())->toContain('Sensitive Formula')
        ->not->toContain('Foreign Secret');
    $this->assertDatabaseHas('audit_logs', [
        'event' => 'analytics.company_exported',
        'company_id' => $company->getKey(),
    ]);
});
