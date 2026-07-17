<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\Occupation;
use App\Models\Plan;
use App\Models\Skill;
use Illuminate\Database\Seeder;

class DomainCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'basic',
                'name' => 'Basic',
                'description' => 'Ein aktiver Job und persönlicher Support für den Einstieg.',
                'price_cents' => 299_900,
                'term_months' => 2,
                'active_jobs_limit' => 1,
                'seat_limit' => 1,
                'ai_credits_monthly' => 0,
                'job_boosts_per_term' => 0,
                'visa_credits_per_term' => 0,
                'features' => ['job_templates' => 'standard', 'support' => 'chatbot_tickets'],
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'description' => 'Recruiting-KI, drei aktive Jobs und fünf Visumspakete.',
                'price_cents' => 349_900,
                'term_months' => 4,
                'active_jobs_limit' => 3,
                'seat_limit' => 5,
                'ai_credits_monthly' => 250,
                'job_boosts_per_term' => 1,
                'visa_credits_per_term' => 5,
                'features' => ['job_templates' => 'premium', 'ai_matching' => true],
            ],
            [
                'slug' => 'premium',
                'name' => 'Premium',
                'description' => 'Die umfassende Recruiting- und Visa-Lösung.',
                'price_cents' => 499_900,
                'term_months' => 6,
                'active_jobs_limit' => 5,
                'seat_limit' => 15,
                'ai_credits_monthly' => 750,
                'job_boosts_per_term' => 3,
                'visa_credits_per_term' => 15,
                'features' => ['job_templates' => 'premium', 'ai_matching' => true],
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Individuelle Konditionen und unbegrenzte Kontingente.',
                'price_cents' => null,
                'term_months' => null,
                'active_jobs_limit' => null,
                'seat_limit' => null,
                'ai_credits_monthly' => null,
                'job_boosts_per_term' => null,
                'visa_credits_per_term' => null,
                'is_enterprise' => true,
                'features' => ['job_templates' => 'premium', 'ai_matching' => true],
            ],
        ];

        foreach ($plans as $attributes) {
            $plan = Plan::query()->updateOrCreate(
                ['slug' => $attributes['slug']],
                array_merge([
                    'currency' => 'EUR',
                    'is_enterprise' => false,
                    'is_active' => true,
                ], $attributes),
            );

            foreach ([
                'active_jobs' => $plan->active_jobs_limit,
                'seats' => $plan->seat_limit,
                'ai_credits_monthly' => $plan->ai_credits_monthly,
                'job_boosts_per_term' => $plan->job_boosts_per_term,
                'visa_credits_per_term' => $plan->visa_credits_per_term,
            ] as $key => $limit) {
                $plan->entitlements()->updateOrCreate(
                    ['key' => $key],
                    ['value' => ['limit' => $limit, 'unlimited' => $limit === null]],
                );
            }
        }

        $catalog = [
            'elektriker' => [
                'names' => ['Elektriker', 'Electrician'],
                'skills' => ['Schaltschrankbau', 'Industrie', 'Gebäudetechnik', 'KNX', 'SPS', 'Siemens', 'ABB', 'Hochspannung'],
            ],
            'elektroniker' => [
                'names' => ['Elektroniker', 'Electronics technician'],
                'skills' => ['Elektronik', 'Messtechnik', 'Fehlersuche', 'Automatisierung'],
            ],
            'lkw-fahrer' => [
                'names' => ['LKW-Fahrer', 'Truck driver'],
                'skills' => ['CE', 'ADR', 'Gefahrgut', 'Kühltransport', 'Fernverkehr'],
            ],
            'pflegefachkraft' => [
                'names' => ['Pflegefachkraft', 'Registered nurse'],
                'skills' => ['Intensivpflege', 'Altenpflege', 'OP', 'Kinderpflege'],
            ],
            'hilfsarbeiter' => [
                'names' => ['Hilfsarbeiter', 'General worker'],
                'skills' => ['Lager', 'Produktion', 'Montage', 'Kommissionierung'],
            ],
        ];

        foreach ($catalog as $slug => $definition) {
            $occupation = Occupation::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name_de' => $definition['names'][0],
                    'name_en' => $definition['names'][1],
                    'is_active' => true,
                ],
            );

            foreach ($definition['skills'] as $skillName) {
                $skill = Skill::query()->updateOrCreate(
                    ['slug' => str($skillName)->ascii()->slug()],
                    ['name_de' => $skillName, 'name_en' => $skillName, 'is_active' => true],
                );
                $occupation->skills()->syncWithoutDetaching($skill);
            }
        }

        foreach ([
            ['de', 'Deutsch', 'German'],
            ['en', 'Englisch', 'English'],
            ['pl', 'Polnisch', 'Polish'],
            ['ro', 'Rumänisch', 'Romanian'],
            ['hr', 'Kroatisch', 'Croatian'],
            ['es', 'Spanisch', 'Spanish'],
            ['pt', 'Portugiesisch', 'Portuguese'],
        ] as [$code, $de, $en]) {
            Language::query()->updateOrCreate(
                ['code' => $code],
                ['name_de' => $de, 'name_en' => $en],
            );
        }
    }
}
