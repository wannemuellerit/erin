<?php

use App\Contracts\PartnerProvider;
use App\Enums\PartnerServiceType;
use App\Enums\UserRole;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CountryServiceRule;
use App\Models\FeatureFlag;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\PartnerCase;
use App\Models\PartnerCaseArtifact;
use App\Models\PartnerCaseTask;
use App\Models\PartnerIntegrationReceipt;
use App\Models\PartnerMember;
use App\Models\PartnerOffering;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Models\VisaCase;
use App\Notifications\ActivityNotification;
use App\Services\Partners\PartnerCaseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ErinPartnerProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.partners.webhook_secret', str_repeat('p', 32));
    app()->instance(PartnerProvider::class, new ErinPartnerProvider);
});

function erinEnablePartnerService(PartnerServiceType $type, string $country = 'DE'): void
{
    FeatureFlag::query()->create(['key' => 'partner_'.$type->value, 'name' => $type->value, 'enabled' => true, 'rollout_percentage' => 100]);
    CountryServiceRule::query()->create([
        'country_code' => $country, 'service_type' => $type->value, 'status' => 'enabled',
        'currency_code' => 'EUR', 'legal_basis' => 'Approved test basis', 'version' => 1, 'approved_at' => now(),
    ]);
}

function erinPartnerOrganization(array $attributes = []): PartnerOrganization
{
    return PartnerOrganization::query()->create([
        'name' => 'Verified Partner', 'slug' => 'verified-partner', 'category' => 'language_course',
        'country_codes' => ['DE'], 'service_types' => PartnerServiceType::values(), 'languages' => ['de', 'en'],
        'integration_mode' => 'manual', 'contract_status' => 'approved', 'dpa_status' => 'approved',
        'legal_approved_at' => now(), ...$attributes,
    ]);
}

function erinPartnerOffering(PartnerOrganization $organization, PartnerServiceType $type = PartnerServiceType::LanguageCourse): PartnerOffering
{
    return PartnerOffering::query()->create([
        'partner_organization_id' => $organization->getKey(), 'service_type' => $type,
        'country_code' => 'DE', 'title' => 'Versioned offer', 'attributes' => ['level' => 'B2'],
        'version' => 1, 'is_active' => true,
    ]);
}

function erinPartnerCase(User $candidate, PartnerOrganization $organization, PartnerOffering $offering, array $attributes = []): PartnerCase
{
    return PartnerCase::query()->create([
        'service_type' => $offering->service_type, 'candidate_user_id' => $candidate->getKey(),
        'partner_organization_id' => $organization->getKey(), 'partner_offering_id' => $offering->getKey(),
        'target_country_code' => 'DE', 'status' => 'ready_to_transfer', 'public_status' => 'ready',
        'purpose' => 'Language course enrolment', 'shared_data_categories' => ['identity'],
        'consented_at' => now(), 'consent_expires_at' => now()->addDays(30), ...$attributes,
    ]);
}

it('fails closed until flag, country law, contract and dpa are all approved', function () {
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);

    $this->actingAs($candidate)->post('/candidate/services', [
        'service_type' => 'language_course', 'target_country_code' => 'DE', 'purpose' => 'Course',
        'shared_data_categories' => ['identity'], 'consent' => true,
    ])->assertNotFound();

    erinEnablePartnerService(PartnerServiceType::LanguageCourse);
    $organization = erinPartnerOrganization(['dpa_status' => 'pending']);
    $offering = erinPartnerOffering($organization);
    $this->actingAs($candidate)->post('/candidate/services', [
        'service_type' => 'language_course', 'target_country_code' => 'DE', 'offering_id' => $offering->getKey(),
        'purpose' => 'Course', 'shared_data_categories' => ['identity'], 'consent' => true,
    ])->assertUnprocessable();

    $organization->update(['dpa_status' => 'approved']);
    $this->actingAs($candidate)->post('/candidate/services', [
        'service_type' => 'language_course', 'target_country_code' => 'DE', 'offering_id' => $offering->getKey(),
        'purpose' => 'Course', 'shared_data_categories' => ['identity'], 'consent' => true,
    ])->assertRedirect();
    expect(PartnerCase::query()->sole())->purpose->toBe('Course')->consent_expires_at->not->toBeNull();
});

it('shows candidates only their own cases and partners only explicitly assigned active-consent cases', function () {
    erinEnablePartnerService(PartnerServiceType::LanguageCourse);
    $organization = erinPartnerOrganization();
    $offering = erinPartnerOffering($organization);
    $candidate = User::factory()->create();
    $otherCandidate = User::factory()->create();
    $partner = User::factory()->create(['role' => UserRole::Partner]);
    $member = PartnerMember::query()->create([
        'partner_organization_id' => $organization->getKey(), 'user_id' => $partner->getKey(), 'role' => 'case_worker',
        'capabilities' => ['partner.cases.view', 'partner.cases.manage'], 'accepted_at' => now(),
    ]);
    $assigned = erinPartnerCase($candidate, $organization, $offering, ['assigned_member_id' => $member->getKey()]);
    erinPartnerCase($otherCandidate, $organization, $offering, ['withdrawn_at' => now(), 'status' => 'withdrawn']);

    $this->actingAs($candidate)->get('/candidate/services')->assertOk()->assertInertia(fn (Assert $page) => $page->where('cases.0.id', $assigned->public_id)->has('cases', 1));
    $this->actingAs($partner)->get('/partner/cases')->assertOk()->assertInertia(fn (Assert $page) => $page->where('cases.0.id', $assigned->public_id)->has('cases', 1));

    expect(app(PartnerCaseAccess::class)->canView($partner, $assigned))->toBeTrue();
    $assigned->update(['consent_expires_at' => now()->subMinute()]);
    expect(app(PartnerCaseAccess::class)->canView($partner, $assigned->fresh()))->toBeFalse();
});

it('transfers exactly once and revokes access and grants immediately on withdrawal', function () {
    erinEnablePartnerService(PartnerServiceType::LanguageCourse);
    $organization = erinPartnerOrganization();
    $offering = erinPartnerOffering($organization);
    $candidate = User::factory()->create();
    $case = erinPartnerCase($candidate, $organization, $offering);

    $this->actingAs($candidate)->post("/candidate/services/{$case->public_id}/transfer")->assertRedirect();
    $this->actingAs($candidate)->post("/candidate/services/{$case->public_id}/transfer")->assertRedirect();
    expect(app(PartnerProvider::class)->transfers)->toHaveCount(1);
    expect(PartnerIntegrationReceipt::query()->count())->toBe(1);

    $this->actingAs($candidate)->post("/candidate/services/{$case->public_id}/withdraw")->assertRedirect();
    expect($case->fresh())->withdrawn_at->not->toBeNull()->external_reference->toBeNull();
});

it('rejects credentials, pins, activation codes, diagnoses and automated recognition decisions', function (PartnerServiceType $type, string $kind, array $payload, ?string $authority) {
    erinEnablePartnerService($type);
    $organization = erinPartnerOrganization();
    $offering = erinPartnerOffering($organization, $type);
    $candidate = User::factory()->create();
    $case = erinPartnerCase($candidate, $organization, $offering);

    $this->actingAs($candidate)->post("/candidate/services/{$case->public_id}/artifacts", [
        'kind' => $kind, 'title' => 'Forbidden payload', 'payload' => $payload, 'status' => 'accepted', 'authority' => $authority,
    ])->assertUnprocessable();
})->with([
    'bank pin' => [PartnerServiceType::Bank, 'appointment_confirmation', ['pin' => '1234'], null],
    'connectivity activation code' => [PartnerServiceType::Connectivity, 'order_confirmation', ['activation_code' => 'secret'], null],
    'insurance diagnosis' => [PartnerServiceType::HealthInsurance, 'application', ['diagnosis' => 'private'], null],
    'recognition without human authority' => [PartnerServiceType::Recognition, 'agency_decision', ['reference' => 'A-1'], null],
]);

it('accepts signed provider status callbacks idempotently without exposing payload data', function () {
    erinEnablePartnerService(PartnerServiceType::LanguageCourse);
    $organization = erinPartnerOrganization(['integration_mode' => 'api']);
    $offering = erinPartnerOffering($organization);
    $case = erinPartnerCase(User::factory()->create(), $organization, $offering);
    $payload = json_encode(['event_id' => 'event-1', 'case_id' => $case->public_id, 'status' => 'completed', 'public_status' => 'completed', 'summary' => 'Course completed'], JSON_THROW_ON_ERROR);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ERIN_SIGNATURE' => hash_hmac('sha256', $payload, str_repeat('p', 32))];

    $this->call('POST', "/integrations/partners/{$organization->slug}/webhook", [], [], [], $server, $payload)->assertOk()->assertJsonPath('duplicate', false);
    $this->call('POST', "/integrations/partners/{$organization->slug}/webhook", [], [], [], $server, $payload)->assertOk()->assertJsonPath('duplicate', true);
    expect($case->fresh())->status->toBe('completed');
    expect(PartnerIntegrationReceipt::query()->count())->toBe(1);
});

it('rejects weak, oversized, cross-partner and conflicting partner callbacks', function () {
    erinEnablePartnerService(PartnerServiceType::LanguageCourse);
    $organization = erinPartnerOrganization(['integration_mode' => 'api']);
    $other = erinPartnerOrganization(['slug' => 'other-partner', 'integration_mode' => 'api']);
    $case = erinPartnerCase(User::factory()->create(), $organization, erinPartnerOffering($organization));
    $send = function (PartnerOrganization $target, array $data, string $secret = 'pppppppppppppppppppppppppppppppp') {
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        return $this->call('POST', "/integrations/partners/{$target->slug}/webhook", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ERIN_SIGNATURE' => hash_hmac('sha256', $body, $secret),
        ], $body);
    };
    $payload = [
        'event_id' => 'event-conflict',
        'case_id' => $case->public_id,
        'status' => 'completed',
        'public_status' => 'completed',
        'summary' => 'Course completed',
    ];

    config()->set('services.partners.webhook_secret', 'weak');
    $send($organization, $payload, 'weak')->assertServiceUnavailable();

    config()->set('services.partners.webhook_secret', str_repeat('p', 32));
    config()->set('services.partners.webhook_max_bytes', 8);
    $send($organization, $payload)->assertStatus(413);

    config()->set('services.partners.webhook_max_bytes', 4096);
    $send($other, $payload)->assertNotFound();
    $send($organization, $payload)->assertOk();
    $send($organization, [...$payload, 'status' => 'rejected', 'public_status' => 'rejected'])
        ->assertConflict();

    expect($case->fresh()->status)->toBe('completed')
        ->and(PartnerIntegrationReceipt::query()->count())->toBe(1);
});

it('captures complete translation order details', function () {
    erinEnablePartnerService(PartnerServiceType::Translation);
    $candidate = User::factory()->create(['role' => UserRole::Candidate]);

    $this->actingAs($candidate)->post('/candidate/services', [
        'service_type' => 'translation',
        'target_country_code' => 'DE',
        'purpose' => 'Certified diploma translation',
        'shared_data_categories' => ['documents'],
        'consent' => true,
    ])->assertInvalid([
        'service_details.source_language',
        'service_details.target_language',
        'service_details.document_type',
        'service_details.page_count',
        'service_details.deadline',
    ]);

    $this->actingAs($candidate)->post('/candidate/services', [
        'service_type' => 'translation',
        'target_country_code' => 'DE',
        'purpose' => 'Certified diploma translation',
        'shared_data_categories' => ['documents'],
        'service_details' => [
            'source_language' => 'PL',
            'target_language' => 'DE',
            'document_type' => 'diploma',
            'page_count' => 4,
            'deadline' => now()->addWeeks(2)->toDateString(),
            'offer_minor' => 12900,
        ],
        'consent' => true,
    ])->assertRedirect();

    expect(PartnerCase::query()->sole()->service_details)->toMatchArray([
        'source_language' => 'PL',
        'target_language' => 'DE',
        'document_type' => 'diploma',
        'page_count' => 4,
        'offer_minor' => 12900,
    ]);
});

it('pins a clean document hash and stores every translation as a new version', function () {
    $profile = CandidateProfile::factory()->create();
    $candidate = $profile->user;
    $candidate->forceFill(['role' => UserRole::Candidate])->save();
    $organization = erinPartnerOrganization();
    $offering = erinPartnerOffering($organization, PartnerServiceType::Translation);
    $partner = User::factory()->create(['role' => UserRole::Partner]);
    $member = PartnerMember::query()->create([
        'partner_organization_id' => $organization->getKey(),
        'user_id' => $partner->getKey(),
        'role' => 'case_worker',
        'capabilities' => ['partner.cases.view', 'partner.cases.manage'],
        'accepted_at' => now(),
    ]);
    $case = erinPartnerCase($candidate, $organization, $offering, ['assigned_member_id' => $member->getKey()]);
    $document = CandidateDocument::query()->create([
        'candidate_profile_id' => $profile->getKey(),
        'type' => 'qualification',
        'title' => 'Original diploma',
        'disk' => 'private',
        'path' => 'candidate/original-diploma.pdf',
        'original_name' => 'diploma.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => str_repeat('a', 64),
        'status' => 'verified',
        'scan_completed_at' => now(),
        'scan_result' => 'clean',
    ]);

    $this->actingAs($candidate)->post("/candidate/services/{$case->public_id}/grants", [
        'document_id' => $document->getKey(),
        'purpose' => 'Translate this exact diploma version',
        'expires_at' => now()->addWeek()->toIso8601String(),
    ])->assertRedirect();

    foreach (['First certified translation', 'Corrected certified translation'] as $title) {
        $this->actingAs($partner)->post("/partner/cases/{$case->public_id}/artifacts", [
            'kind' => 'translation',
            'title' => $title,
            'payload' => ['translator' => 'Certified Translator A', 'certified' => true],
            'status' => 'accepted',
            'authority' => 'Regional Court Register',
            'source_document_id' => $document->getKey(),
        ])->assertRedirect();
    }

    expect($case->grants()->sole()->document_sha256)->toBe(str_repeat('a', 64))
        ->and(PartnerCaseArtifact::query()->orderBy('version')->pluck('version')->all())->toBe([1, 2])
        ->and(PartnerCaseArtifact::query()->pluck('source_checksum')->unique()->all())->toBe([str_repeat('a', 64)])
        ->and($document->fresh()->title)->toBe('Original diploma');
});

it('rejects unscanned translation sources', function () {
    $profile = CandidateProfile::factory()->create();
    $candidate = $profile->user;
    $candidate->forceFill(['role' => UserRole::Candidate])->save();
    $organization = erinPartnerOrganization();
    $offering = erinPartnerOffering($organization, PartnerServiceType::Translation);
    $case = erinPartnerCase($candidate, $organization, $offering);
    $document = CandidateDocument::query()->create([
        'candidate_profile_id' => $profile->getKey(),
        'type' => 'qualification',
        'title' => 'Quarantined diploma',
        'disk' => 'private',
        'path' => 'quarantine/diploma.pdf',
        'original_name' => 'diploma.pdf',
        'sha256' => str_repeat('b', 64),
        'status' => 'in_review',
        'scan_result' => 'pending',
    ]);

    $this->actingAs($candidate)->post("/candidate/services/{$case->public_id}/grants", [
        'document_id' => $document->getKey(),
        'purpose' => 'Attempted early share',
        'expires_at' => now()->addWeek()->toIso8601String(),
    ])->assertUnprocessable();
});

it('limits recognition decisions to authorities and creates idempotent tasks, notifications and safe visa events', function () {
    Notification::fake();
    $profile = CandidateProfile::factory()->create();
    $candidate = $profile->user;
    $candidate->forceFill(['role' => UserRole::Candidate])->save();
    $organization = erinPartnerOrganization();
    $offering = erinPartnerOffering($organization, PartnerServiceType::Recognition);
    $caseWorker = User::factory()->create(['role' => UserRole::Partner]);
    $workerMember = PartnerMember::query()->create([
        'partner_organization_id' => $organization->getKey(),
        'user_id' => $caseWorker->getKey(),
        'role' => 'case_worker',
        'capabilities' => ['partner.cases.view', 'partner.cases.manage'],
        'accepted_at' => now(),
    ]);
    $authority = User::factory()->create(['role' => UserRole::Partner]);
    $authorityMember = PartnerMember::query()->create([
        'partner_organization_id' => $organization->getKey(),
        'user_id' => $authority->getKey(),
        'role' => 'authority',
        'capabilities' => ['partner.cases.view', 'partner.cases.manage'],
        'accepted_at' => now(),
    ]);
    $case = erinPartnerCase($candidate, $organization, $offering, ['assigned_member_id' => $workerMember->getKey()]);

    $decision = [
        'kind' => 'agency_decision',
        'title' => 'Recognition decision',
        'payload' => ['reference' => 'AUTH-2026-1'],
        'status' => 'accepted',
        'authority' => 'Competent State Authority',
    ];
    $this->actingAs($candidate)->post("/candidate/services/{$case->public_id}/artifacts", $decision)->assertForbidden();
    $this->actingAs($caseWorker)->post("/partner/cases/{$case->public_id}/artifacts", $decision)->assertForbidden();
    $case->update(['assigned_member_id' => $authorityMember->getKey()]);
    $this->actingAs($authority)->post("/partner/cases/{$case->public_id}/artifacts", $decision)->assertRedirect();

    $company = Company::factory()->create();
    $job = JobPosting::factory()->create(['company_id' => $company->getKey()]);
    $application = JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $profile->getKey(),
    ]);
    $visaCase = VisaCase::query()->create([
        'application_id' => $application->getKey(),
        'company_id' => $company->getKey(),
        'candidate_profile_id' => $profile->getKey(),
    ]);

    $payload = [
        'status' => 'waiting_for_candidate',
        'public_status' => 'action_required',
        'summary' => 'Please provide a certified copy.',
    ];
    $this->actingAs($authority)->patch("/partner/cases/{$case->public_id}", $payload)->assertRedirect();
    $this->actingAs($authority)->patch("/partner/cases/{$case->public_id}", $payload)->assertRedirect();

    expect(PartnerCaseTask::query()->count())->toBe(1)
        ->and($visaCase->events()->where('event', 'partner_service.status_changed')->count())->toBe(2)
        ->and($visaCase->events()->latest()->firstOrFail()->payload)->toMatchArray([
            'partner_case_id' => $case->public_id,
            'service_type' => 'recognition',
            'public_status' => 'action_required',
        ]);
    Notification::assertSentToTimes($candidate, ActivityNotification::class, 1);
});
