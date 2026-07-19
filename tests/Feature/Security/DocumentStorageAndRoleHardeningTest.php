<?php

use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function erinSecureDocument(CandidateProfile $profile): CandidateDocument
{
    $path = "candidates/{$profile->getKey()}/documents/cv.pdf";
    Storage::disk('private')->put($path, 'secure-document');

    return CandidateDocument::query()->create([
        'candidate_profile_id' => $profile->getKey(),
        'type' => CandidateDocumentType::Cv,
        'title' => 'Lebenslauf',
        'disk' => 'private',
        'path' => $path,
        'original_name' => 'lebenslauf.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 15,
        'status' => CandidateDocumentStatus::Verified,
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
        'verified_at' => now(),
        'shared_with_employers' => true,
    ]);
}

function erinDocumentCompany(User $member, CompanyMemberRole $role = CompanyMemberRole::Recruiter): Company
{
    $company = Company::factory()->create();
    CompanyMembership::query()->create([
        'company_id' => $company->getKey(),
        'user_id' => $member->getKey(),
        'role' => $role,
        'accepted_at' => now(),
    ]);

    return $company;
}

it('requires authentication even when a candidate document link is validly signed', function () {
    Storage::fake('private');
    $profile = CandidateProfile::factory()->create();
    $document = erinSecureDocument($profile);
    $url = URL::temporarySignedRoute(
        'documents.download',
        now()->addMinutes(10),
        ['document' => $document],
    );

    $this->get($url)
        ->assertRedirect(route('login'));
});

it('keeps document grants bound to the exact candidate application and accepted company member', function () {
    Storage::fake('private');
    $profile = CandidateProfile::factory()->create();
    $otherProfile = CandidateProfile::factory()->create();
    $document = erinSecureDocument($profile);
    $recruiter = User::factory()->create(['role' => UserRole::Company]);
    $otherRecruiter = User::factory()->create(['role' => UserRole::Company]);
    $company = erinDocumentCompany($recruiter);
    erinDocumentCompany($otherRecruiter);
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $recruiter->getKey(),
    ]);
    $wrongApplication = JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $otherProfile->getKey(),
    ]);
    DB::table('document_access_grants')->insert([
        'candidate_document_id' => $document->getKey(),
        'company_id' => $company->getKey(),
        'application_id' => $wrongApplication->getKey(),
        'granted_by' => $profile->user_id,
        'expires_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $url = URL::temporarySignedRoute(
        'documents.download',
        now()->addMinutes(10),
        ['document' => $document],
    );

    $this->actingAs($recruiter)->get($url)->assertForbidden();
    $this->actingAs($otherRecruiter)->get($url)->assertForbidden();

    $application = JobApplication::factory()->create([
        'job_posting_id' => $job->getKey(),
        'candidate_profile_id' => $profile->getKey(),
    ]);
    DB::table('document_access_grants')->insert([
        'candidate_document_id' => $document->getKey(),
        'company_id' => $company->getKey(),
        'application_id' => $application->getKey(),
        'granted_by' => $profile->user_id,
        'expires_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($recruiter)
        ->get($url)
        ->assertOk()
        ->assertDownload('lebenslauf.pdf');
});

it('allows only the owner, an explicitly granted company, or a superadmin to download documents', function () {
    Storage::fake('private');
    $profile = CandidateProfile::factory()->create();
    $document = erinSecureDocument($profile);
    $support = User::factory()->create(['role' => UserRole::Support]);
    $superadmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $url = URL::temporarySignedRoute(
        'documents.download',
        now()->addMinutes(10),
        ['document' => $document],
    );

    $this->actingAs($profile->user)->get($url)->assertOk();
    $this->actingAs($support)->get($url)->assertForbidden();
    $this->actingAs($superadmin)->get($url)->assertOk();
});

it('keeps document review and platform mutations out of the support role', function () {
    $support = User::factory()->create(['role' => UserRole::Support]);
    $superadmin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $this->actingAs($support)->get(route('admin.documents.index'))->assertForbidden();
    $this->actingAs($support)
        ->patch(route('admin.settings.platform.update'), [])
        ->assertForbidden();
    $this->actingAs($superadmin)->get(route('admin.documents.index'))->assertOk();
});

it('enforces the superadmin file-size setting before storing a candidate upload', function () {
    Storage::fake('private');
    $profile = CandidateProfile::factory()->create();
    $profile->user->update(['onboarding_completed_at' => now()]);
    app(PlatformSettings::class)->put('uploads.max_file_size_mb', 1, 'uploads');

    $this->actingAs($profile->user)
        ->post(route('candidate.profile.documents'), [
            'type' => CandidateDocumentType::Cv->value,
            'title' => 'Zu große Datei',
            'file' => UploadedFile::fake()->create('cv.pdf', 2048, 'application/pdf'),
        ])
        ->assertSessionHasErrors('file');

    expect($profile->documents()->count())->toBe(0);
});

it('rejects uploads that would exceed the configured per-user storage quota', function () {
    Storage::fake('private');
    $profile = CandidateProfile::factory()->create();
    $profile->user->update(['onboarding_completed_at' => now()]);
    app(PlatformSettings::class)->put('uploads.max_file_size_mb', 5, 'uploads');
    app(PlatformSettings::class)->put('uploads.user_quota_mb', 10, 'uploads');
    $profile->documents()->create([
        'type' => CandidateDocumentType::Cv,
        'title' => 'Bestehend',
        'disk' => 'private',
        'path' => 'existing.pdf',
        'original_name' => 'existing.pdf',
        'size_bytes' => 10 * 1024 * 1024,
        'status' => CandidateDocumentStatus::Uploaded,
    ]);

    $this->actingAs($profile->user)
        ->post(route('candidate.profile.documents'), [
            'type' => CandidateDocumentType::Qualification->value,
            'title' => 'Noch ein Dokument',
            'file' => UploadedFile::fake()->create('qualification.pdf', 1024, 'application/pdf'),
        ])
        ->assertSessionHasErrors('file');

    expect($profile->documents()->count())->toBe(1);
});

it('publishes scheduled advertisements only to the configured authenticated audience', function () {
    $settings = app(PlatformSettings::class);
    $settings->put('ads.dashboard', [
        ...PlatformSettings::DEFAULT_DASHBOARD_AD,
        'enabled' => true,
        'audience' => 'candidate',
        'title_de' => 'Sprachkurs verfügbar',
        'title_en' => 'Language course available',
        'body_de' => 'Jetzt anmelden.',
        'body_en' => 'Register now.',
        'cta_label_de' => 'Mehr erfahren',
        'cta_label_en' => 'Learn more',
        'url' => 'https://example.test/sprachkurs',
        'starts_at' => now()->subMinute()->toIso8601String(),
        'ends_at' => now()->addHour()->toIso8601String(),
    ], 'ads', true);
    $candidate = User::factory()->create(['role' => UserRole::Candidate, 'locale' => 'de']);
    $companyUser = User::factory()->create(['role' => UserRole::Company, 'locale' => 'de']);

    $this->actingAs($candidate)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('platform.dashboard_ad.title', 'Sprachkurs verfügbar')
            ->where('platform.dashboard_ad.url', 'https://example.test/sprachkurs'));
    $this->actingAs($companyUser)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('platform.dashboard_ad', null));
});

it('records authenticated page views and denied actions in the user activity history', function () {
    Storage::fake('private');
    $profile = CandidateProfile::factory()->create();
    $document = erinSecureDocument($profile);
    $stranger = User::factory()->create(['role' => UserRole::Candidate]);
    $url = URL::temporarySignedRoute(
        'documents.download',
        now()->addMinutes(10),
        ['document' => $document],
    );

    $this->actingAs($stranger)->get(route('dashboard'))->assertOk();
    $this->actingAs($stranger)->get($url)->assertForbidden();

    expect(AuditLog::query()
        ->where('actor_id', $stranger->getKey())
        ->where('event', 'user.page_viewed')
        ->where('metadata->route', 'dashboard')
        ->exists())->toBeTrue()
        ->and(AuditLog::query()
            ->where('actor_id', $stranger->getKey())
            ->where('event', 'user.page_viewed')
            ->where('metadata->route', 'documents.download')
            ->where('metadata->response_status', 403)
            ->exists())->toBeTrue();
});
