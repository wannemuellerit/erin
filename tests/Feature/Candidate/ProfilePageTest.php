<?php

use App\Models\CandidateProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
