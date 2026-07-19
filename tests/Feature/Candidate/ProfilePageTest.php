<?php

use App\Jobs\ScanCandidateProfilePhoto;
use App\Models\CandidateProfile;
use App\Models\Language;
use App\Models\Occupation;
use App\Models\Skill;
use App\Services\Audit\AuditLogger;
use App\Services\Candidates\ProfileCompletenessCalculator;
use App\Services\Documents\ClamAvScanner;
use Database\Seeders\DomainCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function erinCandidateProfilePayload(CandidateProfile $profile): array
{
    return [
        'first_name' => 'Marta',
        'last_name' => 'Nowak',
        'email' => 'marta.profile@wannemueller.dev',
        'birth_date' => '1990-04-15',
        'gender' => 'weiblich',
        'nationality_country_code' => 'PL',
        'current_country_code' => 'PL',
        'current_city' => 'Wrocław',
        'phone' => '+48 555',
        'whatsapp' => '+48 555',
        'summary' => 'Erfahrene Elektrikerin mit Schwerpunkt Industrieanlagen und sicherer Kommunikation im internationalen Team.',
        'occupation_id' => Occupation::query()->value('id'),
        'current_position' => 'Elektrikerin',
        'desired_position' => 'Industrieelektrikerin',
        'experience_years' => 8,
        'highest_qualification' => 'Technikerin',
        'driving_licenses' => ['B', 'CE'],
        'travel_ready' => true,
        'relocation_ready' => true,
        'available_from' => now()->addMonth()->toDateString(),
        'salary_expectation_cents' => 4200000,
        'salary_currency' => 'EUR',
        'employment_preferences' => ['full_time', 'permanent'],
        'weekly_hours' => 40,
        'requires_visa' => false,
        'has_work_permit' => true,
        'skills' => [['id' => Skill::query()->value('id'), 'proficiency' => 4, 'experience_years' => 5]],
        'languages' => [['id' => Language::query()->value('id'), 'level' => 'B2']],
        'experiences' => [],
        'educations' => [],
        'availability' => [
            ['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'timezone' => 'Europe/Berlin'],
            ['weekday' => 1, 'starts_at' => '14:00', 'ends_at' => '16:00', 'timezone' => 'Europe/Berlin'],
        ],
    ];
}

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

it('updates, reorders and deletes only the owners history records without recreating retained rows', function () {
    $this->seed(DomainCatalogSeeder::class);
    $profile = CandidateProfile::factory()->create();
    $first = $profile->experiences()->create([
        'employer' => 'Alt Eins',
        'position' => 'Elektrikerin',
        'started_at' => '2018-01-01',
        'sort_order' => 0,
    ]);
    $second = $profile->experiences()->create([
        'employer' => 'Alt Zwei',
        'position' => 'Technikerin',
        'started_at' => '2020-01-01',
        'sort_order' => 1,
    ]);
    $payload = erinCandidateProfilePayload($profile);
    $payload['experiences'] = [
        [
            'id' => $second->getKey(),
            'employer' => 'Neu Zwei',
            'position' => 'Technikerin',
            'country_code' => 'PL',
            'started_at' => '2020-01-01',
            'ended_at' => null,
            'is_current' => true,
            'description' => 'Aktuelle Tätigkeit',
        ],
        [
            'employer' => 'Neu Drei',
            'position' => 'SPS-Spezialistin',
            'country_code' => 'DE',
            'started_at' => '2024-01-01',
            'ended_at' => '2025-01-01',
            'is_current' => false,
            'description' => null,
        ],
    ];

    $this->actingAs($profile->user)
        ->put(route('candidate.profile.update'), $payload)
        ->assertRedirect();

    expect($profile->experiences()->orderBy('sort_order')->pluck('employer')->all())
        ->toBe(['Neu Zwei', 'Neu Drei'])
        ->and($profile->experiences()->whereKey($second)->exists())->toBeTrue()
        ->and($profile->experiences()->whereKey($first)->exists())->toBeFalse()
        ->and($profile->user->availabilitySlots()->count())->toBe(2);
});

it('rejects foreign history ids and overlapping availability slots atomically', function () {
    $this->seed(DomainCatalogSeeder::class);
    $profile = CandidateProfile::factory()->create();
    $foreign = CandidateProfile::factory()->create()->experiences()->create([
        'employer' => 'Fremd',
        'position' => 'Fremd',
        'started_at' => '2020-01-01',
    ]);
    $payload = erinCandidateProfilePayload($profile);
    $payload['experiences'] = [[
        'id' => $foreign->getKey(),
        'employer' => 'Manipuliert',
        'position' => 'Fremd',
        'country_code' => 'PL',
        'started_at' => '2020-01-01',
        'ended_at' => null,
        'is_current' => false,
        'description' => null,
    ]];

    $this->actingAs($profile->user)
        ->put(route('candidate.profile.update'), $payload)
        ->assertSessionHasErrors('experiences');
    expect($foreign->refresh()->employer)->toBe('Fremd');
    $profile->user->refresh();

    $payload = erinCandidateProfilePayload($profile);
    $payload['availability'][1]['starts_at'] = '11:00';
    $this->actingAs($profile->user)
        ->put(route('candidate.profile.update'), $payload)
        ->assertSessionHasErrors('availability');
    expect($profile->user->availabilitySlots()->count())->toBe(0);
});

it('requires re-verification after an email change and blocks publication below eighty percent', function () {
    $this->seed(DomainCatalogSeeder::class);
    Notification::fake();
    $profile = CandidateProfile::factory()->create([
        'published_at' => null,
        'completeness' => 10,
        'summary' => 'Ein vollständiger Kurztext für das Profil ist vorhanden.',
        'occupation_id' => Occupation::query()->value('id'),
        'current_country_code' => 'PL',
    ]);
    $payload = erinCandidateProfilePayload($profile);

    $this->actingAs($profile->user)
        ->put(route('candidate.profile.update'), $payload)
        ->assertRedirect();

    expect($profile->user->refresh()->email)->toBe('marta.profile@wannemueller.dev')
        ->and($profile->user->email_verified_at)->toBeNull();

    $profile->user->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($profile->user)
        ->post(route('candidate.profile.publish'))
        ->assertSessionHasErrors('profile');
    expect($profile->refresh()->published_at)->toBeNull();
});
