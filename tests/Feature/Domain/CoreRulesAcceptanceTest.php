<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\JobApplication;
use App\Models\JobInvitation;
use App\Models\JobPosting;
use App\Models\Occupation;
use App\Models\User;
use App\Policies\CandidateProfilePolicy;
use App\Services\Applications\ApplicationWorkflow;
use App\Services\Billing\EntitlementService;
use App\Services\Matching\CandidateMatchService;
use App\Services\Matching\MatchScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Company}
 */
function erinAcceptanceEmployer(?string $subscriptionStatus = 'active'): array
{
    $owner = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create(['subscription_status' => $subscriptionStatus]);

    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $owner->getKey(),
        'role' => CompanyMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return [$owner, $company];
}

/**
 * @param  list<CandidateDocumentType>  $types
 */
function erinAcceptanceDocuments(CandidateProfile $profile, array $types): void
{
    foreach ($types as $index => $type) {
        CandidateDocument::query()->create([
            'candidate_profile_id' => $profile->getKey(),
            'type' => $type,
            'title' => $type->value,
            'disk' => 'local',
            'path' => "acceptance/{$profile->getKey()}/{$index}.pdf",
            'original_name' => "{$type->value}.pdf",
            'mime_type' => 'application/pdf',
            'status' => CandidateDocumentStatus::Verified,
            'scan_result' => 'clean',
            'scan_completed_at' => now(),
        ]);
    }
}

it('blocks unpaid company portal states while preserving access for past due companies', function () {
    [$owner, $company] = erinAcceptanceEmployer(null);
    $entitlements = app(EntitlementService::class);

    foreach ([null, 'incomplete', 'unpaid', 'canceled'] as $status) {
        $company->forceFill(['subscription_status' => $status])->save();

        expect($entitlements->hasPortalAccess($company->fresh()))->toBeFalse();

        $this->actingAs($owner)
            ->withSession(['active_company_id' => $company->getKey()])
            ->get(route('employer.candidates.index'))
            ->assertRedirect(route('employer.billing'));
    }

    $company->update(['subscription_status' => 'past_due']);

    expect($entitlements->hasPortalAccess($company->fresh()))->toBeTrue();

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.candidates.index'))
        ->assertOk();
});

it('only discovers published candidates and reveals identity after an application or accepted invitation', function () {
    [$owner, $company] = erinAcceptanceEmployer();
    [$otherOwner] = erinAcceptanceEmployer();
    $policy = app(CandidateProfilePolicy::class);

    $published = CandidateProfile::factory()->create([
        'first_name' => 'Ana',
        'last_name' => 'Marin',
        'current_country_code' => 'RO',
        'current_city' => 'Cluj',
        'desired_position' => 'Pflegefachkraft',
        'published_at' => now(),
    ]);
    $hidden = CandidateProfile::factory()->create(['published_at' => null]);

    expect(CandidateProfile::query()->published()->pluck('id')->all())
        ->toBe([$published->getKey()])
        ->and($published->anonymizedLabel())->toBe('Pflegefachkraft · RO')
        ->and($published->anonymizedLabel())->not->toContain('Ana', 'Marin', 'Cluj')
        ->and($policy->viewIdentity($owner, $published))->toBeFalse();

    $this->actingAs($owner)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.candidates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('employer/Candidates')
            ->has('candidates', 1)
            ->has('candidates.0', fn (Assert $candidate) => $candidate
                ->where('id', $published->getKey())
                ->where('label', 'Pflegefachkraft · RO')
                ->missing('first_name')
                ->missing('last_name')
                ->missing('current_city')
                ->missing('email')
                ->missing('phone')
                ->etc()));

    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
    ]);
    $application = JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $published->getKey(),
        'identity_revealed_at' => null,
    ]);

    expect($policy->viewIdentity($owner, $published->refresh()))->toBeFalse();

    $application->update(['identity_revealed_at' => now()]);

    expect($policy->viewIdentity($owner, $published->refresh()))->toBeTrue()
        ->and($policy->viewIdentity($otherOwner, $published))->toBeFalse();

    $invitedCandidate = CandidateProfile::factory()->create();
    JobInvitation::query()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $invitedCandidate->getKey(),
        'invited_by' => $owner->getKey(),
        'status' => 'accepted',
        'responded_at' => now(),
    ]);

    expect($policy->viewIdentity($owner, $invitedCandidate))->toBeTrue()
        ->and(CandidateProfile::query()->published()->whereKey($hidden)->exists())->toBeFalse();
});

it('requires a published profile with at least eighty percent completeness before applying', function () {
    [$owner, $company] = erinAcceptanceEmployer();
    $profile = CandidateProfile::factory()->create([
        'completeness' => 100,
        'published_at' => null,
    ]);
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    expect($profile->canApply())->toBeFalse();

    $profile->update(['completeness' => 79, 'published_at' => now()]);

    $this->actingAs($profile->user)
        ->from(route('candidate.jobs'))
        ->post(route('candidate.jobs.apply', $job))
        ->assertRedirect(route('candidate.jobs'))
        ->assertSessionHasErrors('profile');

    $this->assertDatabaseMissing('applications', [
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $profile->getKey(),
    ]);

    $profile->update(['completeness' => 80]);

    expect($profile->fresh()->canApply())->toBeTrue();

    $this->actingAs($profile->user)
        ->post(route('candidate.jobs.apply', $job))
        ->assertRedirect(route('candidate.applications'));

    $this->assertDatabaseHas('applications', [
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $profile->getKey(),
        'status' => ApplicationStatus::New->value,
    ]);
});

it('returns the exact explainable match weights and ignores protected characteristics', function () {
    [$owner, $company] = erinAcceptanceEmployer();
    $occupation = Occupation::query()->create([
        'slug' => 'pflegefachkraft-acceptance',
        'name_de' => 'Pflegefachkraft',
        'name_en' => 'Registered nurse',
        'is_active' => true,
    ]);
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
        'occupation_id' => $occupation->getKey(),
        'expected_experience_years' => 2,
        'employment_type' => 'full_time',
        'compensation_max_cents' => 50_000_00,
    ]);

    $common = [
        'occupation_id' => $occupation->getKey(),
        'experience_years' => 5,
        'employment_preferences' => ['full_time'],
        'available_from' => now()->addMonth(),
        'salary_expectation_cents' => 45_000_00,
        'has_work_permit' => true,
        'relocation_ready' => true,
    ];
    $candidateA = CandidateProfile::factory()->create([
        ...$common,
        'birth_date' => '1968-02-01',
        'gender' => 'female',
        'nationality_country_code' => 'RO',
    ]);
    $candidateB = CandidateProfile::factory()->create([
        ...$common,
        'birth_date' => '2001-11-20',
        'gender' => 'male',
        'nationality_country_code' => 'PT',
    ]);

    $scoreDocuments = [
        CandidateDocumentType::Cv,
        CandidateDocumentType::Qualification,
        CandidateDocumentType::LanguageCertificate,
    ];
    erinAcceptanceDocuments($candidateA, $scoreDocuments);
    erinAcceptanceDocuments($candidateB, $scoreDocuments);
    erinAcceptanceDocuments($candidateA, [CandidateDocumentType::HealthCertificate]);

    $matching = app(CandidateMatchService::class);
    $scoreA = $matching->for($candidateA, $job);
    $scoreB = $matching->for($candidateB, $job);
    $weights = array_map(
        static fn (array $factor): int => $factor['weight'],
        $scoreA['factors'],
    );

    expect($scoreA)->toBe($scoreB)
        ->and($scoreA['score'])->toBe(100)
        ->and($weights)->toBe(MatchScoreCalculator::WEIGHTS)
        ->and(array_intersect(
            array_keys($scoreA['factors']),
            ['nationality', 'origin', 'gender', 'age', 'birth_date', 'health'],
        ))->toBe([]);
});

it('maps every application state to one ATS column and rejects invalid workflow jumps', function () {
    $expectedStages = [
        ApplicationStatus::New->value => 'new',
        ApplicationStatus::InReview->value => 'interesting',
        ApplicationStatus::DocumentsMissing->value => 'interesting',
        ApplicationStatus::InterviewScheduled->value => 'interview',
        ApplicationStatus::InterviewCompleted->value => 'interview',
        ApplicationStatus::FinalSelection->value => 'final_selection',
        ApplicationStatus::Accepted->value => 'accepted',
        ApplicationStatus::VisaInProgress->value => 'accepted',
        ApplicationStatus::ContractSent->value => 'accepted',
        ApplicationStatus::ContractSigned->value => 'accepted',
        ApplicationStatus::Hired->value => 'hired',
        ApplicationStatus::Rejected->value => 'closed',
        ApplicationStatus::Withdrawn->value => 'closed',
    ];

    expect(collect(ApplicationStatus::cases())
        ->mapWithKeys(fn (ApplicationStatus $status): array => [
            $status->value => $status->pipelineStage(),
        ])
        ->sortKeys()
        ->all())->toBe(collect($expectedStages)->sortKeys()->all());

    $workflow = app(ApplicationWorkflow::class);
    $validPath = [
        [ApplicationStatus::New, ApplicationStatus::InReview],
        [ApplicationStatus::InReview, ApplicationStatus::InterviewScheduled],
        [ApplicationStatus::InterviewScheduled, ApplicationStatus::InterviewCompleted],
        [ApplicationStatus::InterviewCompleted, ApplicationStatus::FinalSelection],
        [ApplicationStatus::FinalSelection, ApplicationStatus::Accepted],
        [ApplicationStatus::Accepted, ApplicationStatus::VisaInProgress],
        [ApplicationStatus::VisaInProgress, ApplicationStatus::ContractSigned],
        [ApplicationStatus::ContractSigned, ApplicationStatus::Hired],
    ];

    foreach ($validPath as [$from, $to]) {
        expect($workflow->canTransition($from, $to))->toBeTrue();
    }

    expect($workflow->canTransition(ApplicationStatus::New, ApplicationStatus::Hired))->toBeFalse()
        ->and($workflow->canTransition(ApplicationStatus::Rejected, ApplicationStatus::InReview))->toBeFalse()
        ->and($workflow->canTransition(ApplicationStatus::Hired, ApplicationStatus::Withdrawn))->toBeFalse()
        ->and(fn () => $workflow->assertCanTransition(
            ApplicationStatus::New,
            ApplicationStatus::Hired,
        ))->toThrow(DomainException::class);
});

it('keeps candidate documents private and grants expiring company access explicitly', function () {
    Storage::fake('local');
    [$owner, $company] = erinAcceptanceEmployer();
    [$stranger] = erinAcceptanceEmployer();
    $profile = CandidateProfile::factory()->create();
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $owner->getKey(),
    ]);
    $application = JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $profile->getKey(),
    ]);
    $path = 'candidate-documents/private-cv.pdf';
    Storage::disk('local')->put($path, 'private cv content');
    $document = CandidateDocument::query()->create([
        'candidate_profile_id' => $profile->getKey(),
        'type' => CandidateDocumentType::Cv,
        'title' => 'Lebenslauf',
        'disk' => 'local',
        'path' => $path,
        'original_name' => 'lebenslauf.pdf',
        'mime_type' => 'application/pdf',
        'status' => CandidateDocumentStatus::Verified,
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
        'shared_with_employers' => false,
    ]);
    $downloadUrl = URL::temporarySignedRoute(
        'documents.download',
        now()->addMinutes(10),
        ['document' => $document],
    );

    $this->actingAs($owner)->get($downloadUrl)->assertForbidden();

    $this->actingAs($profile->user)
        ->from(route('candidate.applications'))
        ->post(route('documents.grant', [$document, $application]))
        ->assertRedirect(route('candidate.applications'));

    $this->assertDatabaseHas('document_access_grants', [
        'candidate_document_id' => $document->getKey(),
        'company_id' => $company->getKey(),
        'application_id' => $application->getKey(),
        'granted_by' => $profile->user_id,
        'revoked_at' => null,
    ]);

    $this->actingAs($owner)
        ->get($downloadUrl)
        ->assertOk()
        ->assertDownload('lebenslauf.pdf');

    $this->actingAs($stranger)->get($downloadUrl)->assertForbidden();

    DB::table('document_access_grants')
        ->where('candidate_document_id', $document->getKey())
        ->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($owner)->get($downloadUrl)->assertForbidden();
});
