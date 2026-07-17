<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'slug' => str($name)->slug(),
            'name' => ucfirst($name),
            'price_cents' => fake()->numberBetween(100_00, 5_000_00),
            'currency' => 'EUR',
            'term_months' => 2,
            'active_jobs_limit' => 1,
            'seat_limit' => 1,
            'ai_credits_monthly' => 0,
            'job_boosts_per_term' => 0,
            'visa_credits_per_term' => 0,
            'is_enterprise' => false,
            'is_active' => true,
            'features' => [],
        ];
    }
}
