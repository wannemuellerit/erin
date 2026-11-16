<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Jobs\ScanCandidateDocument;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('private');
    Queue::fake();
});

function fadenCandidateDocument(CandidateProfile $profile): CandidateDocument
{
    Storage::disk('private')->put("candidates/{$profile->id}/documents/old.pdf", 'old');

    return $profile->documents()->create([
        'type' => CandidateDocumentType::Cv, 'title' => 'Lebenslauf', 'disk' => 'private',
        'path' => "candidates/{$profile->id}/documents/old.pdf", 'original_name' => 'old.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 3, 'status' => CandidateDocumentStatus::Verified,
        'scan_result' => 'clean', 'verified_at' => now(), 'shared_with_employers' => true,
    ]);
}

it('uploads edits replaces and deletes a private document through one lifecycle', function () {
    $profile = CandidateProfile::factory()->create();
    $profile->user->update(['onboarding_completed_at' => now()]);
    $this->actingAs($profile->user)->post(route('candidate.profile.documents'), [
        'type' => CandidateDocumentType::Qualification->value,
        'title' => 'Abschluss',
        'expires_at' => now()->addYear()->toDateString(),
        'file' => UploadedFile::fake()->create('abschluss.pdf', 100, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $document = $profile->documents()->firstOrFail();
    expect($document->disk)->toBe('private')->and($document->scan_result)->toBe('pending');
    Queue::assertPushed(ScanCandidateDocument::class);

    $this->actingAs($profile->user)->patch(route('candidate.profile.documents.update', $document), [
        'type' => CandidateDocumentType::LanguageCertificate->value,
        'title' => 'Deutsch B2',
        'expires_at' => now()->addMonths(6)->toDateString(),
    ])->assertSessionHasNoErrors();
    expect($document->refresh()->status)->toBe(CandidateDocumentStatus::Uploaded)
        ->and($document->verified_at)->toBeNull();

    $oldPath = $document->path;
    $this->actingAs($profile->user)->post(route('candidate.profile.documents.replace', $document), [
        'type' => CandidateDocumentType::LanguageCertificate->value,
        'title' => 'Deutsch B2 – neue Version',
        'file' => UploadedFile::fake()->create('deutsch-b2.pdf', 120, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    expect($document->refresh()->version)->toBe(2)
        ->and($document->scan_result)->toBe('pending')
        ->and(Storage::disk('private')->exists($oldPath))->toBeFalse();

    $newPath = $document->path;
    $this->actingAs($profile->user)->delete(route('candidate.profile.documents.destroy', $document))
        ->assertSessionHasNoErrors();
    expect(CandidateDocument::withTrashed()->findOrFail($document->id)->trashed())->toBeTrue()
        ->and(Storage::disk('private')->exists($newPath))->toBeFalse();
});

it('revokes every active company grant when a document is replaced or deleted', function () {
    $profile = CandidateProfile::factory()->create();
    $profile->user->update(['onboarding_completed_at' => now()]);
    $document = fadenCandidateDocument($profile);
    $member = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();
    CompanyMembership::query()->create(['company_id' => $company->id, 'user_id' => $member->id, 'role' => CompanyMemberRole::Recruiter, 'accepted_at' => now()]);
    $job = JobPosting::factory()->create(['company_id' => $company->id, 'created_by' => $member->id]);
    $application = JobApplication::factory()->create(['job_posting_id' => $job->id, 'candidate_profile_id' => $profile->id]);
    DB::table('document_access_grants')->insert(['candidate_document_id' => $document->id, 'company_id' => $company->id, 'application_id' => $application->id, 'granted_by' => $profile->user_id, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $url = URL::temporarySignedRoute('documents.download', now()->addMinutes(10), ['document' => $document]);
    $this->actingAs($member)->get($url)->assertOk();

    $this->actingAs($profile->user)->post(route('candidate.profile.documents.replace', $document), [
        'type' => CandidateDocumentType::Cv->value, 'title' => 'Neu',
        'file' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    expect(DB::table('document_access_grants')->where('candidate_document_id', $document->id)->whereNull('revoked_at')->exists())->toBeFalse();
    $this->actingAs($member)->get($url)->assertStatus(423);
});

it('hides document mutations from other candidates and all guests', function () {
    $owner = CandidateProfile::factory()->create();
    $owner->user->update(['onboarding_completed_at' => now()]);
    $other = CandidateProfile::factory()->create();
    $other->user->update(['onboarding_completed_at' => now()]);
    $document = fadenCandidateDocument($owner);
    $payload = ['type' => CandidateDocumentType::Cv->value, 'title' => 'Fremd', 'expires_at' => null];

    $this->patch(route('candidate.profile.documents.update', $document), $payload)->assertRedirect(route('login'));
    $this->actingAs($other->user)->patch(route('candidate.profile.documents.update', $document), $payload)->assertNotFound();
    $this->actingAs($other->user)->delete(route('candidate.profile.documents.destroy', $document))->assertNotFound();
});

it('rejects expired or closed-case grants and lets the owner revoke an active grant', function () {
    $profile = CandidateProfile::factory()->create();
    $document = fadenCandidateDocument($profile);
    $member = User::factory()->create(['role' => UserRole::Company]);
    $company = Company::factory()->create();
    CompanyMembership::query()->create(['company_id' => $company->id, 'user_id' => $member->id, 'role' => CompanyMemberRole::Recruiter, 'accepted_at' => now()]);
    $job = JobPosting::factory()->create(['company_id' => $company->id, 'created_by' => $member->id]);
    $application = JobApplication::factory()->create(['job_posting_id' => $job->id, 'candidate_profile_id' => $profile->id]);

    $document->update(['expires_at' => now()->subDay()]);
    $this->actingAs($profile->user)->post(route('documents.grant', [$document, $application]))->assertStatus(422);
    $document->update(['expires_at' => now()->addMonth()]);
    $application->update(['status' => ApplicationStatus::Rejected]);
    $this->actingAs($profile->user)->post(route('documents.grant', [$document, $application]))->assertStatus(422);
    $application->update(['status' => ApplicationStatus::InReview]);
    $this->actingAs($profile->user)->post(route('documents.grant', [$document, $application]))->assertSessionHasNoErrors();
    $downloadUrl = URL::temporarySignedRoute('documents.download', now()->addMinutes(10), ['document' => $document]);
    $this->actingAs($member)->get($downloadUrl)->assertOk();

    $document->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($member)->get($downloadUrl)->assertForbidden();
    $document->update(['expires_at' => now()->addMonth()]);
    $application->update(['status' => ApplicationStatus::Withdrawn]);
    $this->actingAs($member)->get($downloadUrl)->assertForbidden();

    $this->actingAs($profile->user)->delete(route('documents.grant.revoke', [$document, $application]))->assertSessionHasNoErrors();

    expect(DB::table('document_access_grants')->where('candidate_document_id', $document->id)->whereNull('revoked_at')->exists())->toBeFalse()
        ->and($document->refresh()->shared_with_employers)->toBeFalse();
});
