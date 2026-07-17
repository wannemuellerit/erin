<?php

use App\Enums\ApplicationStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\InterviewStatus;
use App\Enums\UserRole;
use App\Models\CandidateInternalReview;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CompanyTrustMetric;
use App\Models\Feedback;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\ModerationCase;
use App\Models\Occupation;
use App\Models\User;
use App\Services\Trust\CompanyTrustMetricService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Company}
 */
function erinTrustEmployer(CompanyMemberRole $role = CompanyMemberRole::Owner): array
{
    $user = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();

    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => $role,
        'accepted_at' => now(),
    ]);

    return [$user, $company];
}

function erinTrustApplication(
    Company $company,
    User $creator,
    ApplicationStatus $status,
    ?CandidateProfile $candidate = null,
): JobApplication {
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $creator->getKey(),
    ]);

    return JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => ($candidate ?? CandidateProfile::factory()->create())->getKey(),
        'status' => $status,
        'applied_at' => now()->subDays(10),
    ]);
}

it('calculates traceable trust rates but keeps them private below five distinct cases', function () {
    [$owner, $company] = erinTrustEmployer();
    $statuses = [
        ApplicationStatus::Hired,
        ApplicationStatus::ContractSigned,
        ApplicationStatus::InReview,
        ApplicationStatus::InReview,
    ];
    $applications = [];

    foreach ($statuses as $index => $status) {
        $application = erinTrustApplication($company, $owner, $status);
        $applications[] = $application;
        Interview::query()->create([
            'application_id' => $application->getKey(),
            'organizer_id' => $owner->getKey(),
            'proposed_by' => $owner->getKey(),
            'status' => $index === 3 ? InterviewStatus::NoShow : InterviewStatus::Completed,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
            'metadata' => $index === 3 ? ['company_attended' => false] : null,
        ]);
    }

    $contractComplaint = $applications[2];
    Feedback::query()->create([
        'author_id' => $contractComplaint->candidateProfile->user_id,
        'subject_company_id' => $company->getKey(),
        'application_id' => $contractComplaint->getKey(),
        'sentiment' => 'negative',
        'reason_code' => 'contract_not_honored',
        'metrics' => ['contract_honored' => false],
        'status' => 'pending',
    ]);

    $service = app(CompanyTrustMetricService::class);
    $privateMetric = $service->recalculate($company);

    expect($privateMetric->cases_count)->toBe(4)
        ->and($privateMetric->response_rate)->toBeNull()
        ->and($privateMetric->interview_attendance_rate)->toBeNull()
        ->and($privateMetric->contract_compliance_rate)->toBeNull()
        ->and($privateMetric->publicPayload())->toBeNull();

    $fifthApplication = erinTrustApplication($company, $owner, ApplicationStatus::New);
    Interview::query()->create([
        'application_id' => $fifthApplication->getKey(),
        'organizer_id' => $owner->getKey(),
        'proposed_by' => $owner->getKey(),
        'status' => InterviewStatus::Completed,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addHour(),
    ]);

    $publicMetric = $service->recalculate($company);

    expect($publicMetric->cases_count)->toBe(5)
        ->and((float) $publicMetric->response_rate)->toBe(80.0)
        ->and((float) $publicMetric->interview_attendance_rate)->toBe(80.0)
        ->and((float) $publicMetric->contract_compliance_rate)->toBe(66.67)
        ->and($publicMetric->publicPayload())->not->toBeNull();
});

it('marks exactly the best five percent of sufficiently rated companies as top companies', function () {
    $metrics = collect();

    foreach (range(0, 20) as $index) {
        $company = Company::factory()->create();
        $metrics->push(CompanyTrustMetric::query()->create([
            'company_id' => $company->getKey(),
            'response_rate' => 50 + $index,
            'interview_attendance_rate' => 50 + $index,
            'contract_compliance_rate' => 50 + $index,
            'cases_count' => 5,
            'calculated_at' => now(),
        ]));
    }

    CompanyTrustMetric::query()->create([
        'company_id' => Company::factory()->create()->getKey(),
        'response_rate' => 100,
        'interview_attendance_rate' => 100,
        'contract_compliance_rate' => 100,
        'cases_count' => 4,
        'calculated_at' => now(),
    ]);

    app(CompanyTrustMetricService::class)->recalculateTopCompanies();

    expect(CompanyTrustMetric::query()->where('is_top_company', true)->count())->toBe(2)
        ->and(CompanyTrustMetric::query()
            ->where('is_top_company', true)
            ->orderByDesc('response_rate')
            ->pluck('id')
            ->all())->toBe([
                $metrics->get(20)->getKey(),
                $metrics->get(19)->getKey(),
            ]);
});

it('recalculates company trust after candidate feedback without changing moderation state', function () {
    [$owner, $company] = erinTrustEmployer();
    $application = erinTrustApplication($company, $owner, ApplicationStatus::Accepted);

    $this->actingAs($application->candidateProfile->user)
        ->from(route('candidate.applications'))
        ->post(route('feedback.store', $application), [
            'sentiment' => 'positive',
            'reason_code' => 'reliable',
            'metrics' => ['company_responded' => true],
        ])
        ->assertRedirect(route('candidate.applications'));

    $feedback = Feedback::query()->sole();

    expect($feedback->status)->toBe('pending')
        ->and(ModerationCase::query()->count())->toBe(0)
        ->and($company->trustMetric()->sole()->cases_count)->toBe(1)
        ->and($company->trustMetric()->sole()->publicPayload())->toBeNull();
});

it('only exposes company trust metrics in the candidate marketplace after five cases', function () {
    $occupation = Occupation::query()->create([
        'slug' => 'trust-electrician',
        'name_de' => 'Elektriker',
        'name_en' => 'Electrician',
    ]);
    $profile = CandidateProfile::factory()->create(['occupation_id' => $occupation->getKey()]);
    $publicCompany = Company::factory()->create(['last_active_at' => now()]);
    $privateCompany = Company::factory()->create(['last_active_at' => now()->subDay()]);

    foreach ([$publicCompany, $privateCompany] as $company) {
        JobPosting::factory()->create([
            'company_id' => $company->getKey(),
            'occupation_id' => $occupation->getKey(),
        ]);
    }

    CompanyTrustMetric::query()->create([
        'company_id' => $publicCompany->getKey(),
        'response_rate' => 98,
        'interview_attendance_rate' => 95,
        'contract_compliance_rate' => 97,
        'cases_count' => 5,
        'is_top_company' => true,
        'calculated_at' => now(),
    ]);
    CompanyTrustMetric::query()->create([
        'company_id' => $privateCompany->getKey(),
        'response_rate' => 100,
        'interview_attendance_rate' => 100,
        'contract_compliance_rate' => 100,
        'cases_count' => 4,
        'calculated_at' => now(),
    ]);

    $this->actingAs($profile->user)
        ->get(route('candidate.companies'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('candidate/Companies')
            ->has('companies', 2)
            ->where('companies.0.id', $publicCompany->getKey())
            ->where('companies.0.trust_metrics.response_rate', 98)
            ->where('companies.0.trust_metrics.interview_attendance_rate', 95)
            ->where('companies.0.trust_metrics.contract_compliance_rate', 97)
            ->where('companies.0.trust_metrics.cases_count', 5)
            ->where('companies.0.trust_metrics.is_top_company', true)
            ->missing('companies.0.trust_metric')
            ->where('companies.1.id', $privateCompany->getKey())
            ->where('companies.1.trust_metrics', null)
            ->missing('companies.1.trust_metric'));
});

it('upserts internal candidate reviews per application and reviewer with strict company roles', function () {
    [$owner, $company] = erinTrustEmployer();
    $recruiter = User::factory()->create(['role' => UserRole::Company]);
    $viewer = User::factory()->create(['role' => UserRole::Company]);

    foreach ([
        [$recruiter, CompanyMemberRole::Recruiter],
        [$viewer, CompanyMemberRole::Viewer],
    ] as [$member, $role]) {
        CompanyMembership::query()->create([
            'company_id' => $company->getKey(),
            'user_id' => $member->getKey(),
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }

    $candidate = CandidateProfile::factory()->create();
    $application = erinTrustApplication($company, $owner, ApplicationStatus::InterviewCompleted, $candidate);
    $payload = [
        'metrics' => [
            'punctual' => true,
            'friendly' => true,
            'good_communication' => true,
            'documents_complete' => false,
            'honest_information' => true,
        ],
        'notes' => 'Intern und sachlich.',
    ];

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->put(route('employer.applications.candidate-review', $application), $payload)
        ->assertRedirect();
    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->put(route('employer.applications.candidate-review', $application), [
            ...$payload,
            'notes' => 'Aktualisierte interne Notiz.',
        ])
        ->assertRedirect();

    expect(CandidateInternalReview::query()->count())->toBe(1)
        ->and(CandidateInternalReview::query()->sole()->notes)->toBe('Aktualisierte interne Notiz.');

    $this->actingAs($recruiter)
        ->withSession(['active_company_id' => $company->getKey()])
        ->put(route('employer.applications.candidate-review', $application), $payload)
        ->assertRedirect();

    $secondApplication = erinTrustApplication(
        $company,
        $owner,
        ApplicationStatus::InterviewCompleted,
        $candidate,
    );
    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->put(route('employer.applications.candidate-review', $secondApplication), $payload)
        ->assertRedirect();

    expect(CandidateInternalReview::query()->count())->toBe(3)
        ->and(Feedback::query()->count())->toBe(0)
        ->and(ModerationCase::query()->count())->toBe(0);

    $this->actingAs($viewer)
        ->withSession(['active_company_id' => $company->getKey()])
        ->put(route('employer.applications.candidate-review', $application), $payload)
        ->assertForbidden();

    [$otherOwner, $otherCompany] = erinTrustEmployer();
    $this->actingAs($otherOwner)
        ->withSession(['active_company_id' => $otherCompany->getKey()])
        ->put(route('employer.applications.candidate-review', $application), $payload)
        ->assertNotFound();
});
