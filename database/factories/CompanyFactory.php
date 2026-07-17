<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => str($name)->slug().'-'.fake()->unique()->numberBetween(10, 9999),
            'legal_name' => $name.' GmbH',
            'email' => fake()->companyEmail(),
            'website' => fake()->url(),
            'industry' => fake()->randomElement(['Handwerk', 'Pflege', 'Logistik', 'Industrie']),
            'employee_count' => fake()->numberBetween(10, 500),
            'country_code' => 'DE',
            'city' => fake()->city(),
            'status' => CompanyStatus::Active,
            'subscription_status' => 'active',
            'benefits' => ['accommodation' => true, 'language_course' => true],
        ];
    }
}
