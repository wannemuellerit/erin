<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\CandidateProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobApplication>
 */
class JobApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'job_posting_id' => JobPosting::factory(),
            'candidate_profile_id' => CandidateProfile::factory(),
            'status' => ApplicationStatus::New,
            'cover_letter' => fake()->paragraph(),
            'match_score' => fake()->randomFloat(2, 60, 98),
            'match_breakdown' => [
                'occupation' => 25,
                'skills' => 15,
                'language' => 12,
            ],
            'applied_at' => now(),
        ];
    }
}
