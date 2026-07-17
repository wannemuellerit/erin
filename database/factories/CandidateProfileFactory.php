<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateProfile>
 */
class CandidateProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => UserRole::Candidate]),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'birth_date' => fake()->dateTimeBetween('-55 years', '-20 years'),
            'nationality_country_code' => fake()->randomElement(['PL', 'RO', 'HR', 'ES', 'PT']),
            'current_country_code' => fake()->randomElement(['PL', 'RO', 'HR', 'ES', 'PT']),
            'current_city' => fake()->city(),
            'summary' => fake()->paragraph(),
            'current_position' => 'Fachkraft',
            'desired_position' => 'Fachkraft',
            'experience_years' => fake()->randomFloat(1, 1, 20),
            'travel_ready' => true,
            'relocation_ready' => true,
            'available_from' => now()->addMonth(),
            'weekly_hours' => 40,
            'requires_visa' => false,
            'has_work_permit' => true,
            'completeness' => 90,
            'published_at' => now(),
        ];
    }
}
