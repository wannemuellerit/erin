<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->jobTitle();

        return [
            'company_id' => Company::factory(),
            'created_by' => User::factory()->state(['role' => UserRole::Company]),
            'title' => $title,
            'slug' => str($title)->slug().'-'.fake()->unique()->numberBetween(10, 9999),
            'position' => $title,
            'description' => fake()->paragraphs(3, true),
            'expected_experience_years' => 2,
            'hours_min' => 35,
            'hours_max' => 40,
            'employment_type' => 'full_time',
            'compensation_min_cents' => 36_000_00,
            'compensation_max_cents' => 48_000_00,
            'currency' => 'EUR',
            'compensation_interval' => 'year',
            'status' => JobStatus::Published,
            'visa_package_available' => true,
            'published_at' => now(),
        ];
    }
}
