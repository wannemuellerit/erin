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
use App\Models\RecruiterReminder;
use App\Models\User;
use App\Services\Analytics\RecruitingAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
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

it('shows only company-visible activity and the current recruiters reminders', function () {
    ['user' => $owner, 'company' => $company] = erinAnalyticsEmployer();
    ['user' => $foreignOwner, 'company' => $foreignCompany] = erinAnalyticsEmployer();
    $colleague = User::factory()->create(['role' => UserRole::Company]);
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $colleague->getKey(),
        'role' => CompanyMemberRole::Recruiter,
        'accepted_at' => now(),
    ]);

    ActivityEntry::query()->create([
        'company_id' => $company->getKey(),
        'actor_id' => $owner->getKey(),
        'event' => 'company.visible',
        'visibility' => 'company',
        'payload' => ['label' => 'Sichtbar'],
        'occurred_at' => now(),
    ]);
    ActivityEntry::query()->create([
        'company_id' => $company->getKey(),
        'actor_id' => $owner->getKey(),
        'subject_user_id' => $owner->getKey(),
        'event' => 'personal.hidden',
        'visibility' => 'personal',
        'occurred_at' => now()->subMinute(),
    ]);
    ActivityEntry::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'actor_id' => $foreignOwner->getKey(),
        'event' => 'foreign.hidden',
        'visibility' => 'company',
        'occurred_at' => now()->subMinutes(2),
    ]);
    RecruiterReminder::query()->create([
        'company_id' => $company->getKey(),
        'creator_id' => $owner->getKey(),
        'assignee_id' => $owner->getKey(),
        'title' => 'Meine Erinnerung',
        'priority' => 'normal',
        'due_at' => now()->addDay(),
    ]);
    RecruiterReminder::query()->create([
        'company_id' => $company->getKey(),
        'creator_id' => $colleague->getKey(),
        'assignee_id' => $colleague->getKey(),
        'title' => 'Fremde Erinnerung',
        'priority' => 'normal',
        'due_at' => now()->addDay(),
    ]);

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.productivity'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employer/Productivity')
            ->where('company_id', $company->getKey())
            ->has('activity', 1)
            ->where('activity.0.event', 'company.visible')
            ->has('reminders', 1)
            ->where('reminders.0.title', 'Meine Erinnerung'));
});

it('keeps live company activity private from platform staff outside an impersonated member session', function () {
    ['user' => $owner, 'company' => $company] = erinAnalyticsEmployer();
    $support = User::factory()->create(['role' => UserRole::Support]);
    $channel = Broadcast::getChannels()['company.{companyId}'];

    expect($channel($owner, $company->getKey()))->toBeTrue()
        ->and($channel($support, $company->getKey()))->toBeFalse();
});
