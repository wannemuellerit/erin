<?php

use App\Enums\CompanyStatus;
use App\Enums\JobStatus;
use App\Models\CandidateProfile;
use App\Models\CompanyTrustMetric;
use App\Models\JobPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders a dedicated published job detail with match and public trust data', function () {
    $profile = CandidateProfile::factory()->create();
    $job = JobPosting::factory()->create();
    CompanyTrustMetric::query()->create([
        'company_id' => $job->company_id,
        'response_rate' => 98,
        'interview_attendance_rate' => 95,
        'contract_compliance_rate' => 97,
        'cases_count' => 5,
        'is_top_company' => true,
        'calculated_at' => now(),
    ]);

    $this->actingAs($profile->user)
        ->get(route('candidate.jobs.show', $job))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('candidate/JobShow')
            ->where('job.id', $job->getKey())
            ->where('job.company.id', $job->company_id)
            ->where('job.company.trust_metrics.cases_count', 5)
            ->has('job.match.score')
            ->where('can_apply', true));
});

it('does not expose draft or paused jobs through the candidate detail route', function (JobStatus $status) {
    $profile = CandidateProfile::factory()->create();
    $job = JobPosting::factory()->create([
        'status' => $status,
        'published_at' => $status === JobStatus::Draft ? null : now(),
    ]);

    $this->actingAs($profile->user)
        ->get(route('candidate.jobs.show', $job))
        ->assertNotFound();
})->with([
    'draft' => JobStatus::Draft,
    'paused' => JobStatus::Paused,
    'archived' => JobStatus::Archived,
]);

it('renders only relevant active companies with thresholded trust metrics', function () {
    $profile = CandidateProfile::factory()->create();
    $job = JobPosting::factory()->create();
    CompanyTrustMetric::query()->create([
        'company_id' => $job->company_id,
        'response_rate' => 100,
        'interview_attendance_rate' => 100,
        'contract_compliance_rate' => 100,
        'cases_count' => 4,
        'is_top_company' => false,
        'calculated_at' => now(),
    ]);

    $this->actingAs($profile->user)
        ->get(route('candidate.companies.show', $job->company))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('candidate/CompanyShow')
            ->where('company.id', $job->company_id)
            ->where('company.trust_metrics', null)
            ->has('jobs', 1)
            ->where('jobs.0.id', $job->getKey()));
});

it('rejects blocked companies and companies without a relevant active job', function () {
    $profile = CandidateProfile::factory()->create();
    $blockedJob = JobPosting::factory()->create();
    $blockedJob->company->update(['status' => CompanyStatus::Blocked]);
    $emptyCompany = $blockedJob->company->replicate();
    $emptyCompany->slug = 'empty-company';
    $emptyCompany->status = CompanyStatus::Active;
    $emptyCompany->save();

    $this->actingAs($profile->user)
        ->get(route('candidate.companies.show', $blockedJob->company))
        ->assertNotFound();

    $this->actingAs($profile->user)
        ->get(route('candidate.companies.show', $emptyCompany))
        ->assertNotFound();
});
