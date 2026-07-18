<?php

use App\Jobs\ScanCandidateProfilePhoto;
use App\Models\CandidateProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Candidates\ProfileCompletenessCalculator;
use App\Services\Documents\ClamAvScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the candidate profile including education records', function () {
    $profile = CandidateProfile::factory()->create();

    $profile->educations()->create([
        'institution' => 'Technische Hochschule',
        'qualification' => 'Elektrotechnik',
        'field' => 'Industrieelektrik',
        'country_code' => 'PL',
        'started_at' => '2014-09-01',
        'completed_at' => '2017-06-30',
    ]);

    $this->actingAs($profile->user)
        ->get(route('candidate.profile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('candidate/Profile')
            ->has('profile.educations', 1)
            ->where('profile.educations.0.qualification', 'Elektrotechnik'));
});

it('quarantines, scans and normalizes a private profile picture before use', function () {
    Storage::fake('private');
    Queue::fake();
    $profile = CandidateProfile::factory()->create([
        'profile_photo_path' => null,
        'completeness' => 0,
    ]);

    $this->actingAs($profile->user)
        ->post(route('candidate.profile.photo.upload'), [
            'photo' => UploadedFile::fake()->image('portrait.png', 900, 600),
        ])
        ->assertRedirect();

    $profile->refresh();
    expect($profile->profile_photo_path)->toBeNull()
        ->and($profile->profile_photo_scan_result)->toBe('pending')
        ->and($profile->profile_photo_quarantine_path)->not->toBeNull();
    Queue::assertPushed(ScanCandidateProfilePhoto::class);

    $scanner = $this->mock(ClamAvScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn('clean');
    (new ScanCandidateProfilePhoto(
        $profile->getKey(),
        (string) $profile->profile_photo_quarantine_path,
    ))->handle(
        $scanner,
        app(ProfileCompletenessCalculator::class),
        app(AuditLogger::class),
    );

    $profile->refresh();
    expect($profile->profile_photo_scan_result)->toBe('clean')
        ->and($profile->profile_photo_path)->toEndWith('.jpg')
        ->and($profile->profile_photo_quarantine_path)->toBeNull()
        ->and($profile->profile_photo_mime_type)->toBe('image/jpeg');
    Storage::disk('private')->assertExists((string) $profile->profile_photo_path);

    $page = $this->actingAs($profile->user)
        ->get(route('candidate.profile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('profile.profile_photo_scan_result', 'clean')
            ->where('profile.profile_photo_url', fn (mixed $url): bool => is_string($url) && str_contains($url, 'signature=')));
    $page->assertOk();
});

it('rejects invalid profile pictures and deletes private photo data on request', function () {
    Storage::fake('private');
    $profile = CandidateProfile::factory()->create([
        'profile_photo_path' => 'candidates/1/profile/photo.jpg',
        'profile_photo_disk' => 'private',
        'profile_photo_scan_result' => 'clean',
    ]);
    Storage::disk('private')->put((string) $profile->profile_photo_path, 'private-image');

    $this->actingAs($profile->user)
        ->post(route('candidate.profile.photo.upload'), [
            'photo' => UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml'),
        ])
        ->assertSessionHasErrors('photo');

    $this->actingAs($profile->user)
        ->delete(route('candidate.profile.photo.delete'))
        ->assertRedirect();

    $profile->refresh();
    expect($profile->profile_photo_path)->toBeNull()
        ->and($profile->profile_photo_scan_result)->toBeNull();
    Storage::disk('private')->assertMissing('candidates/1/profile/photo.jpg');
});
