<?php

use App\Services\Candidates\ProfileCompletenessCalculator;

it('requires eighty percent completeness before applying', function () {
    $calculator = app(ProfileCompletenessCalculator::class);
    $base = [
        'first_name' => 'Ana',
        'last_name' => 'Marin',
        'current_country_code' => 'RO',
        'current_city' => 'Cluj',
        'phone' => '+40 123',
        'profile_photo_path' => 'profiles/ana.jpg',
        'occupation_id' => 1,
        'desired_position' => 'Pflegefachkraft',
        'summary' => 'Erfahrene Pflegefachkraft',
        'work_experiences_count' => 1,
        'skills_count' => 3,
        'languages_count' => 2,
        'educations_count' => 1,
        'has_cv' => true,
        'has_verified_certificate' => false,
        'available_from' => '2026-09-01',
        'relocation_ready' => true,
    ];

    $complete = $calculator->calculate($base);
    $incomplete = $calculator->calculate([
        ...$base,
        'profile_photo_path' => null,
        'skills_count' => 0,
    ]);

    expect($complete['percentage'])->toBe(90)
        ->and($complete['can_apply'])->toBeTrue()
        ->and($incomplete['percentage'])->toBe(75)
        ->and($incomplete['can_apply'])->toBeFalse();
});
