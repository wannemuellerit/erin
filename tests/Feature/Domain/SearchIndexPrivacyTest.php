<?php

use App\Enums\CandidateDocumentStatus;
use App\Enums\CandidateDocumentType;
use App\Enums\JobStatus;
use App\Models\CandidateDocument;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\JobMedia;
use App\Models\JobPosting;
use App\Models\Language;
use App\Models\Occupation;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\EngineManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('scout.driver', 'collection');
    config()->set('scout.queue', false);
    app(EngineManager::class)->forgetEngines();
});

it('indexes only the public professional attributes of published candidates', function () {
    $occupation = Occupation::query()->create([
        'slug' => 'industrial-electrician-search',
        'name_de' => 'Industrieelektriker',
        'name_en' => 'Industrial electrician',
        'is_active' => true,
    ]);
    $skill = Skill::query()->create([
        'slug' => 'switchgear-search',
        'name_de' => 'Schaltschrankbau',
        'name_en' => 'Switchgear construction',
        'is_active' => true,
    ]);
    $language = Language::query()->create([
        'code' => 'de',
        'name_de' => 'Deutsch',
        'name_en' => 'German',
    ]);
    $user = User::factory()->create([
        'name' => 'PRIVATE_USER_NAME_47Q',
        'email' => 'private-search-47q@example.test',
    ]);
    $profile = CandidateProfile::factory()->create([
        'user_id' => $user->getKey(),
        'occupation_id' => $occupation->getKey(),
        'first_name' => 'PRIVATE_FIRST_47Q',
        'last_name' => 'PRIVATE_LAST_47Q',
        'birth_date' => '1989-02-13',
        'gender' => 'PRIVATE_GENDER_47Q',
        'nationality_country_code' => 'RO',
        'current_country_code' => 'PL',
        'current_city' => 'PRIVATE_CITY_47Q',
        'phone' => '+49-PRIVATE-PHONE-47Q',
        'whatsapp' => '+49-PRIVATE-WHATSAPP-47Q',
        'summary' => 'Erfahren in Industrieanlagen und präziser Montage.',
        'current_position' => 'Elektriker',
        'desired_position' => 'Industrieelektriker',
        'experience_years' => 7.5,
        'highest_qualification' => 'Technischer Berufsabschluss',
        'driving_licenses' => ['B', 'CE'],
        'employment_preferences' => ['full_time'],
        'weekly_hours' => 40,
        'travel_ready' => true,
        'relocation_ready' => true,
        'available_from' => '2026-10-01',
        'salary_expectation_cents' => 4_200_00,
        'salary_currency' => 'EUR',
        'requires_visa' => false,
        'has_work_permit' => true,
        'profile_photo_path' => 'PRIVATE_PHOTO_PATH_47Q.jpg',
        'published_at' => now(),
    ]);
    $profile->skills()->attach($skill, [
        'proficiency' => 5,
        'experience_years' => 7,
        'is_verified' => true,
    ]);
    $profile->languages()->attach($language, [
        'level' => 'B2',
        'is_verified' => true,
    ]);
    CandidateDocument::query()->create([
        'candidate_profile_id' => $profile->getKey(),
        'type' => CandidateDocumentType::HealthCertificate,
        'title' => 'PRIVATE_HEALTH_TITLE_47Q',
        'disk' => 'private',
        'path' => 'PRIVATE_DOCUMENT_PATH_47Q.pdf',
        'original_name' => 'PRIVATE_HEALTH_ORIGINAL_47Q.pdf',
        'status' => CandidateDocumentStatus::Verified,
        'scan_result' => 'clean',
    ]);

    $indexData = $profile->fresh()->toSearchableArray();
    $settings = config('scout.meilisearch.index-settings')[CandidateProfile::class];
    $serialized = json_encode($indexData, JSON_THROW_ON_ERROR);

    expect(array_keys($indexData))->toBe($settings['displayedAttributes'])
        ->and($indexData)
        ->toMatchArray([
            'current_country_code' => 'PL',
            'current_position' => 'Elektriker',
            'desired_position' => 'Industrieelektriker',
            'occupation_id' => $occupation->getKey(),
            'skill_ids' => [$skill->getKey()],
            'skill_names' => ['Schaltschrankbau', 'Switchgear construction'],
            'language_codes' => ['de'],
            'language_levels' => ['de:B2'],
            'experience_years' => 7.5,
            'employment_preferences' => ['full_time'],
            'relocation_ready' => true,
            'requires_visa' => false,
            'has_work_permit' => true,
        ]);

    foreach ([
        'PRIVATE_USER_NAME_47Q',
        'private-search-47q@example.test',
        'PRIVATE_FIRST_47Q',
        'PRIVATE_LAST_47Q',
        '1989-02-13',
        'PRIVATE_GENDER_47Q',
        'PRIVATE_CITY_47Q',
        'PRIVATE_PHONE_47Q',
        'PRIVATE_WHATSAPP_47Q',
        'PRIVATE_PHOTO_PATH_47Q',
        'PRIVATE_HEALTH_TITLE_47Q',
        'PRIVATE_DOCUMENT_PATH_47Q',
        'PRIVATE_HEALTH_ORIGINAL_47Q',
    ] as $privateMarker) {
        expect($serialized)->not->toContain($privateMarker);
    }

    expect(array_intersect(array_keys($indexData), [
        'user_id',
        'first_name',
        'last_name',
        'birth_date',
        'gender',
        'nationality_country_code',
        'current_city',
        'phone',
        'whatsapp',
        'profile_photo_path',
        'documents',
        'document_path',
        'health',
    ]))->toBe([]);
});

it('makes unpublished candidates unsearchable even when their public fields match', function () {
    $published = CandidateProfile::factory()->create([
        'desired_position' => 'SearchPrivacyNeedleCandidate',
        'published_at' => now(),
    ]);
    $hidden = CandidateProfile::factory()->create([
        'desired_position' => 'SearchPrivacyNeedleCandidate',
        'published_at' => null,
    ]);

    expect($published->shouldBeSearchable())->toBeTrue()
        ->and($hidden->shouldBeSearchable())->toBeFalse()
        ->and($hidden->toSearchableArray())->toBe([])
        ->and(CandidateProfile::search('SearchPrivacyNeedleCandidate')->get()->modelKeys())
        ->toBe([$published->getKey()]);
});

it('indexes public job data without billing recruiter address or media secrets', function () {
    $occupation = Occupation::query()->create([
        'slug' => 'truck-driver-search',
        'name_de' => 'LKW-Fahrer',
        'name_en' => 'Truck driver',
        'is_active' => true,
    ]);
    $skill = Skill::query()->create([
        'slug' => 'adr-search',
        'name_de' => 'Gefahrgut ADR',
        'name_en' => 'ADR hazardous goods',
        'is_active' => true,
    ]);
    $language = Language::query()->create([
        'code' => 'de',
        'name_de' => 'Deutsch',
        'name_en' => 'German',
    ]);
    $company = Company::factory()->create([
        'name' => 'Öffentliche Logistik GmbH',
        'email' => 'PRIVATE_BILLING_EMAIL_83Z@example.test',
        'phone' => '+49-PRIVATE_COMPANY_PHONE-83Z',
        'vat_id' => 'PRIVATE_VAT_83Z',
        'address_line1' => 'PRIVATE_COMPANY_ADDRESS_83Z',
        'city' => 'Hamburg',
    ]);
    $location = CompanyLocation::query()->create([
        'company_id' => $company->getKey(),
        'name' => 'Nord',
        'country_code' => 'DE',
        'city' => 'Bremen',
        'postal_code' => 'PRIVATE_POSTAL_83Z',
        'address_line1' => 'PRIVATE_LOCATION_ADDRESS_83Z',
        'is_headquarters' => false,
    ]);
    $creator = User::factory()->create([
        'name' => 'PRIVATE_RECRUITER_NAME_83Z',
        'email' => 'private-recruiter-83z@example.test',
    ]);
    $job = JobPosting::factory()->create([
        'company_id' => $company->getKey(),
        'created_by' => $creator->getKey(),
        'location_id' => $location->getKey(),
        'occupation_id' => $occupation->getKey(),
        'title' => 'Berufskraftfahrer Fernverkehr',
        'position' => 'LKW-Fahrer CE',
        'description' => 'Öffentliche Stellenbeschreibung für internationalen Fernverkehr.',
        'status' => JobStatus::Published,
        'published_at' => now(),
    ]);
    $job->skills()->attach($skill, ['importance' => 5]);
    $job->languages()->attach($language, [
        'minimum_level' => 'A2',
        'is_required' => true,
    ]);
    JobMedia::query()->create([
        'job_posting_id' => $job->getKey(),
        'type' => 'document',
        'disk' => 'private',
        'path' => 'PRIVATE_JOB_MEDIA_PATH_83Z.pdf',
        'original_name' => 'PRIVATE_JOB_MEDIA_ORIGINAL_83Z.pdf',
    ]);

    $indexData = $job->fresh()->toSearchableArray();
    $settings = config('scout.meilisearch.index-settings')[JobPosting::class];
    $serialized = json_encode($indexData, JSON_THROW_ON_ERROR);

    expect(array_keys($indexData))->toBe($settings['displayedAttributes'])
        ->and($indexData)
        ->toMatchArray([
            'company_id' => $company->getKey(),
            'company_name' => 'Öffentliche Logistik GmbH',
            'title' => 'Berufskraftfahrer Fernverkehr',
            'position' => 'LKW-Fahrer CE',
            'occupation_id' => $occupation->getKey(),
            'skill_ids' => [$skill->getKey()],
            'skill_names' => ['Gefahrgut ADR', 'ADR hazardous goods'],
            'language_codes' => ['de'],
            'language_levels' => ['de:A2'],
            'location_city' => 'Bremen',
            'location_country_code' => 'DE',
            'status' => JobStatus::Published->value,
        ]);

    foreach ([
        'PRIVATE_BILLING_EMAIL_83Z',
        'PRIVATE_COMPANY_PHONE_83Z',
        'PRIVATE_VAT_83Z',
        'PRIVATE_COMPANY_ADDRESS_83Z',
        'PRIVATE_POSTAL_83Z',
        'PRIVATE_LOCATION_ADDRESS_83Z',
        'PRIVATE_RECRUITER_NAME_83Z',
        'private-recruiter-83z@example.test',
        'PRIVATE_JOB_MEDIA_PATH_83Z',
        'PRIVATE_JOB_MEDIA_ORIGINAL_83Z',
    ] as $privateMarker) {
        expect($serialized)->not->toContain($privateMarker);
    }

    expect(array_intersect(array_keys($indexData), [
        'created_by',
        'creator',
        'company_email',
        'company_phone',
        'vat_id',
        'postal_code',
        'address_line1',
        'latitude',
        'longitude',
        'media',
        'media_path',
    ]))->toBe([]);
});

it('makes drafts paused jobs and jobs without publication timestamp unsearchable', function () {
    $published = JobPosting::factory()->create([
        'title' => 'SearchPrivacyNeedleJob',
        'status' => JobStatus::Published,
        'published_at' => now(),
    ]);
    $draft = JobPosting::factory()->create([
        'title' => 'SearchPrivacyNeedleJob',
        'status' => JobStatus::Draft,
        'published_at' => null,
    ]);
    $paused = JobPosting::factory()->create([
        'title' => 'SearchPrivacyNeedleJob',
        'status' => JobStatus::Paused,
        'published_at' => now(),
    ]);
    $missingTimestamp = JobPosting::factory()->create([
        'title' => 'SearchPrivacyNeedleJob',
        'status' => JobStatus::Published,
        'published_at' => null,
    ]);

    expect($published->shouldBeSearchable())->toBeTrue()
        ->and($draft->shouldBeSearchable())->toBeFalse()
        ->and($paused->shouldBeSearchable())->toBeFalse()
        ->and($missingTimestamp->shouldBeSearchable())->toBeFalse()
        ->and($draft->toSearchableArray())->toBe([])
        ->and($paused->toSearchableArray())->toBe([])
        ->and($missingTimestamp->toSearchableArray())->toBe([])
        ->and(JobPosting::search('SearchPrivacyNeedleJob')->get()->modelKeys())
        ->toBe([$published->getKey()]);
});

it('keeps meilisearch index settings free of protected and private attributes', function () {
    $candidateSettings = config('scout.meilisearch.index-settings')[CandidateProfile::class];
    $candidateAttributes = collect([
        ...$candidateSettings['searchableAttributes'],
        ...$candidateSettings['filterableAttributes'],
        ...$candidateSettings['sortableAttributes'],
        ...$candidateSettings['displayedAttributes'],
    ])->unique()->values()->all();

    expect(array_intersect($candidateAttributes, [
        'user_id',
        'first_name',
        'last_name',
        'birth_date',
        'age',
        'gender',
        'nationality_country_code',
        'origin',
        'current_city',
        'phone',
        'whatsapp',
        'email',
        'profile_photo_path',
        'health',
        'documents',
        'document_path',
    ]))->toBe([]);

    $jobSettings = config('scout.meilisearch.index-settings')[JobPosting::class];
    $jobAttributes = collect([
        ...$jobSettings['searchableAttributes'],
        ...$jobSettings['filterableAttributes'],
        ...$jobSettings['sortableAttributes'],
        ...$jobSettings['displayedAttributes'],
    ])->unique()->values()->all();

    expect(array_intersect($jobAttributes, [
        'created_by',
        'creator',
        'company_email',
        'company_phone',
        'vat_id',
        'postal_code',
        'address_line1',
        'latitude',
        'longitude',
        'media',
        'media_path',
    ]))->toBe([]);
});
