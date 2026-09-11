<?php

use App\Enums\ApplicationStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Company} */
function erinPipelineRecruiter(CompanyMemberRole $role = CompanyMemberRole::Owner): array
{
    $user = User::factory()->create([
        'role' => UserRole::Company,
        'onboarding_completed_at' => now(),
    ]);
    $company = Company::factory()->create();
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => $role,
        'accepted_at' => now(),
    ]);

    return [$user, $company];
}

it('derives every card and allowed destination from the canonical status model', function () {
    [$user, $company] = erinPipelineRecruiter();
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $user->getKey(),
    ]);
    $applications = collect(ApplicationStatus::cases())->mapWithKeys(
        fn (ApplicationStatus $status): array => [
            $status->value => JobApplication::factory()->create([
                'job_posting_id' => $job->getKey(),
                'candidate_profile_id' => CandidateProfile::factory()->create()->getKey(),
                'status' => $status,
            ]),
        ],
    );

    $response = $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.pipeline'))
        ->assertOk();

    $response->assertInertia(function (Assert $page) use ($applications): void {
        foreach (ApplicationStatus::cases() as $status) {
            $stage = $status->pipelineStage();
            $id = $applications->get($status->value)->getKey();
            $page->where("pipeline.{$stage}", fn ($cards) => collect($cards)
                ->contains(fn ($card): bool => $card['id'] === $id
                    && $card['status'] === $status->value
                    && $card['pipeline_stage'] === $stage));
        }
    });
});

it('rejects stale recruiter writes and preserves the first valid transition', function () {
    [$firstRecruiter, $company] = erinPipelineRecruiter();
    $secondRecruiter = User::factory()->create([
        'role' => UserRole::Company,
        'onboarding_completed_at' => now(),
    ]);
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $secondRecruiter->getKey(),
        'role' => CompanyMemberRole::Recruiter,
        'accepted_at' => now(),
    ]);
    $application = JobApplication::factory()->create([
        'job_posting_id' => JobPosting::factory()->create([
            'company_id' => $company->getKey(),
            'created_by' => $firstRecruiter->getKey(),
        ])->getKey(),
        'status' => ApplicationStatus::New,
    ]);
    $version = $application->updated_at->toIso8601String();

    $this->actingAs($firstRecruiter)
        ->withSession(['active_company_id' => $company->getKey()])
        ->patch(route('employer.applications.status', $application), [
            'status' => ApplicationStatus::InReview->value,
            'from_status' => ApplicationStatus::New->value,
            'version' => $version,
        ])
        ->assertSessionDoesntHaveErrors();

    $this->actingAs($secondRecruiter)
        ->withSession(['active_company_id' => $company->getKey()])
        ->patch(route('employer.applications.status', $application), [
            'status' => ApplicationStatus::Rejected->value,
            'from_status' => ApplicationStatus::New->value,
            'version' => $version,
        ])
        ->assertSessionHasErrors('status');

    expect($application->refresh()->status)->toBe(ApplicationStatus::InReview)
        ->and($application->statusHistory()->count())->toBe(1);
});

it('keeps timeline and application detail payloads inside the active tenant', function () {
    [$user, $company] = erinPipelineRecruiter();
    [, $foreignCompany] = erinPipelineRecruiter();
    $application = JobApplication::factory()->create([
        'job_posting_id' => JobPosting::factory()->create([
            'company_id' => $company->getKey(),
            'created_by' => $user->getKey(),
        ])->getKey(),
        'cover_letter' => 'Intern sichtbares Anschreiben',
        'match_breakdown' => ['skills' => 90],
        'status' => ApplicationStatus::InReview,
    ]);
    $application->statusHistory()->create([
        'changed_by' => $user->getKey(),
        'from_status' => ApplicationStatus::New->value,
        'to_status' => ApplicationStatus::InReview->value,
        'note' => 'Geprüft',
    ]);
    $foreign = JobApplication::factory()->create([
        'job_posting_id' => JobPosting::factory()->create([
            'company_id' => $foreignCompany->getKey(),
        ])->getKey(),
        'cover_letter' => 'FREMDES_GEHEIMNIS_14',
    ]);

    $this->actingAs($user)
        ->withSession(['active_company_id' => $company->getKey()])
        ->get(route('employer.pipeline'))
        ->assertOk()
        ->assertDontSee('FREMDES_GEHEIMNIS_14')
        ->assertInertia(fn (Assert $page) => $page
            ->where('pipeline.interesting.0.id', $application->getKey())
            ->where('pipeline.interesting.0.cover_letter', 'Intern sichtbares Anschreiben')
            ->where('pipeline.interesting.0.match_breakdown.skills', 90)
            ->has('pipeline.interesting.0.timeline', 2)
            ->where('pipeline.interesting.0.timeline', fn ($events) => collect($events)
                ->contains(fn ($event): bool => ($event['data']['note'] ?? null) === 'Geprüft'))
            ->where('pipeline.interesting', fn ($cards) => collect($cards)
                ->doesntContain('id', $foreign->getKey())));
});

it('keeps support impersonation read only for pipeline mutations', function () {
    [$user, $company] = erinPipelineRecruiter();
    $application = JobApplication::factory()->create([
        'job_posting_id' => JobPosting::factory()->create([
            'company_id' => $company->getKey(),
            'created_by' => $user->getKey(),
        ])->getKey(),
        'status' => ApplicationStatus::New,
    ]);

    $this->actingAs($user)
        ->withSession([
            'active_company_id' => $company->getKey(),
            'impersonation_session_id' => 123,
        ])
        ->patch(route('employer.applications.status', $application), [
            'status' => ApplicationStatus::InReview->value,
            'from_status' => ApplicationStatus::New->value,
            'version' => $application->updated_at->toIso8601String(),
        ])
        ->assertForbidden();

    expect($application->refresh()->status)->toBe(ApplicationStatus::New);
});
