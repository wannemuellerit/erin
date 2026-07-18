<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\CompanyStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApplicationStatusHistory;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Conversation;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Language;
use App\Models\Message;
use App\Models\Occupation;
use App\Models\Plan;
use App\Models\Skill;
use App\Models\TalentList;
use App\Models\TalentListMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use LogicException;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() || ! config('app.demo_mode')) {
            throw new LogicException('Demo-Daten dürfen nur bei aktiviertem APP_DEMO_MODE angelegt werden.');
        }

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@wannemueller.dev'],
            [
                'name' => 'Wannemüller Admin',
                'email_verified_at' => now(),
                'password' => 'password',
                'role' => UserRole::SuperAdmin,
                'status' => UserStatus::Active,
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'onboarding_completed_at' => now(),
            ],
        );

        $companies = $this->seedCompanies();
        $candidates = $this->seedCandidates();
        $this->seedMarketplace($companies, $candidates);

        $admin->forceFill(['last_active_at' => now()])->save();
    }

    /**
     * @return array<string, array{company: Company, owner: User}>
     */
    private function seedCompanies(): array
    {
        $definitions = [
            'mueller' => [
                'owner' => [
                    'name' => 'Marie Müller',
                    'email' => 'unternehmen.mueller@wannemueller.dev',
                ],
                'plan' => 'business',
                'company' => [
                    'slug' => 'mueller-elektrotechnik',
                    'name' => 'Müller Elektrotechnik',
                    'legal_name' => 'Müller Elektrotechnik GmbH',
                    'website' => 'https://mueller-elektro.example',
                    'phone' => '+49 211 555 0101',
                    'vat_id' => 'DE123456789',
                    'industry' => 'Elektrotechnik',
                    'employee_count' => 85,
                    'country_code' => 'DE',
                    'city' => 'Düsseldorf',
                    'postal_code' => '40210',
                    'address_line1' => 'Werkstraße 12',
                    'description' => 'Moderner Elektrotechnikbetrieb mit internationalen Teams und eigener Lehrwerkstatt.',
                    'benefits' => [
                        'accommodation' => true,
                        'language_course' => true,
                        'visa_support' => true,
                        'workwear' => true,
                        'company_vehicle' => false,
                    ],
                ],
            ],
            'rheincargo' => [
                'owner' => [
                    'name' => 'Daniel Schneider',
                    'email' => 'unternehmen.rheincargo@wannemueller.dev',
                ],
                'plan' => 'premium',
                'company' => [
                    'slug' => 'rheincargo-logistik',
                    'name' => 'RheinCargo Logistik',
                    'legal_name' => 'RheinCargo Logistik GmbH',
                    'website' => 'https://rheincargo.example',
                    'phone' => '+49 221 555 0202',
                    'vat_id' => 'DE987654321',
                    'industry' => 'Logistik',
                    'employee_count' => 240,
                    'country_code' => 'DE',
                    'city' => 'Köln',
                    'postal_code' => '50667',
                    'address_line1' => 'Hafenallee 48',
                    'description' => 'Internationales Logistikunternehmen mit moderner Flotte und planbaren Touren.',
                    'benefits' => [
                        'accommodation' => true,
                        'language_course' => true,
                        'visa_support' => true,
                        'workwear' => true,
                        'canteen' => true,
                    ],
                ],
            ],
        ];

        $result = [];

        foreach ($definitions as $key => $definition) {
            $owner = User::query()->updateOrCreate(
                ['email' => $definition['owner']['email']],
                [
                    'name' => $definition['owner']['name'],
                    'email_verified_at' => now(),
                    'password' => 'password',
                    'role' => UserRole::Company,
                    'status' => UserStatus::Active,
                    'locale' => 'de',
                    'timezone' => 'Europe/Berlin',
                    'onboarding_completed_at' => now(),
                    'last_active_at' => now()->subMinutes($key === 'mueller' ? 8 : 22),
                ],
            );
            $plan = Plan::query()->where('slug', $definition['plan'])->firstOrFail();
            $company = Company::query()->updateOrCreate(
                ['slug' => $definition['company']['slug']],
                [
                    ...$definition['company'],
                    'current_plan_id' => $plan->getKey(),
                    'email' => $owner->email,
                    'status' => CompanyStatus::Active,
                    'subscription_status' => 'active',
                    'subscription_started_at' => now()->startOfDay(),
                    'subscription_renews_at' => now()->addMonths($plan->term_months ?? 1)->startOfDay(),
                    'cancel_at_period_end' => false,
                    'last_active_at' => $owner->last_active_at,
                ],
            );

            CompanyMembership::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'user_id' => $owner->getKey()],
                ['role' => CompanyMemberRole::Owner, 'accepted_at' => now()],
            );

            $result[$key] = ['company' => $company, 'owner' => $owner];
        }

        return $result;
    }

    /**
     * @return array<string, CandidateProfile>
     */
    private function seedCandidates(): array
    {
        $definitions = [
            [
                'email' => 'candidate01@wannemueller.dev',
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'occupation' => 'elektriker',
                'country' => 'PL',
                'city' => 'Wrocław',
                'current_position' => 'Industrieelektrikerin',
                'desired_position' => 'Elektrikerin',
                'experience' => 8,
                'skills' => ['schaltschrankbau', 'industrie', 'siemens'],
                'native_language' => 'pl',
                'german_level' => 'B1',
                'summary' => 'Erfahrene Elektrikerin mit Schwerpunkt Industrie und Schaltschrankbau.',
            ],
            [
                'email' => 'candidate02@wannemueller.dev',
                'first_name' => 'Marek',
                'last_name' => 'Nowak',
                'occupation' => 'lkw-fahrer',
                'country' => 'PL',
                'city' => 'Poznań',
                'current_position' => 'Fernfahrer CE',
                'desired_position' => 'LKW-Fahrer',
                'experience' => 11,
                'skills' => ['ce', 'adr', 'fernverkehr'],
                'native_language' => 'pl',
                'german_level' => 'A2',
                'summary' => 'Zuverlässiger CE-Fahrer mit ADR-Nachweis und langjähriger Fernverkehrserfahrung.',
            ],
            [
                'email' => 'candidate03@wannemueller.dev',
                'first_name' => 'Elena',
                'last_name' => 'Popescu',
                'occupation' => 'pflegefachkraft',
                'country' => 'RO',
                'city' => 'Cluj-Napoca',
                'current_position' => 'Pflegefachkraft',
                'desired_position' => 'Pflegefachkraft Intensivpflege',
                'experience' => 6,
                'skills' => ['intensivpflege', 'altenpflege'],
                'native_language' => 'ro',
                'german_level' => 'B2',
                'summary' => 'Pflegefachkraft mit Erfahrung in Intensivpflege und wertschätzender Angehörigenkommunikation.',
            ],
            [
                'email' => 'candidate04@wannemueller.dev',
                'first_name' => 'Andrei',
                'last_name' => 'Ionescu',
                'occupation' => 'elektroniker',
                'country' => 'RO',
                'city' => 'Timișoara',
                'current_position' => 'Automatisierungstechniker',
                'desired_position' => 'Elektroniker Automatisierung',
                'experience' => 7,
                'skills' => ['automatisierung', 'messtechnik', 'fehlersuche'],
                'native_language' => 'ro',
                'german_level' => 'B1',
                'summary' => 'Elektroniker mit fundierter Erfahrung in Automatisierung, Messtechnik und Fehlersuche.',
            ],
            [
                'email' => 'candidate05@wannemueller.dev',
                'first_name' => 'Ivana',
                'last_name' => 'Horvat',
                'occupation' => 'pflegefachkraft',
                'country' => 'HR',
                'city' => 'Zagreb',
                'current_position' => 'OP-Pflegefachkraft',
                'desired_position' => 'Pflegefachkraft OP',
                'experience' => 9,
                'skills' => ['op', 'intensivpflege'],
                'native_language' => 'hr',
                'german_level' => 'B2',
                'summary' => 'Ruhige und strukturierte OP-Pflegefachkraft mit hoher Teamorientierung.',
            ],
            [
                'email' => 'candidate06@wannemueller.dev',
                'first_name' => 'Luka',
                'last_name' => 'Kovač',
                'occupation' => 'lkw-fahrer',
                'country' => 'HR',
                'city' => 'Rijeka',
                'current_position' => 'Berufskraftfahrer',
                'desired_position' => 'LKW-Fahrer Kühltransport',
                'experience' => 5,
                'skills' => ['ce', 'kuhltransport', 'fernverkehr'],
                'native_language' => 'hr',
                'german_level' => 'A2',
                'summary' => 'CE-Fahrer mit Erfahrung im temperaturgeführten Transport und internationalen Touren.',
            ],
            [
                'email' => 'candidate07@wannemueller.dev',
                'first_name' => 'Sofía',
                'last_name' => 'García',
                'occupation' => 'elektriker',
                'country' => 'ES',
                'city' => 'Valencia',
                'current_position' => 'Gebäudeelektrikerin',
                'desired_position' => 'Elektrikerin Gebäudetechnik',
                'experience' => 4,
                'skills' => ['gebaudetechnik', 'knx', 'abb'],
                'native_language' => 'es',
                'german_level' => 'B1',
                'summary' => 'Elektrikerin für Gebäudetechnik mit KNX-Erfahrung und sorgfältiger Dokumentation.',
            ],
            [
                'email' => 'candidate08@wannemueller.dev',
                'first_name' => 'Tiago',
                'last_name' => 'Silva',
                'occupation' => 'hilfsarbeiter',
                'country' => 'PT',
                'city' => 'Braga',
                'current_position' => 'Produktionsmitarbeiter',
                'desired_position' => 'Produktionshelfer',
                'experience' => 3,
                'skills' => ['produktion', 'montage', 'kommissionierung'],
                'native_language' => 'pt',
                'german_level' => 'A2',
                'summary' => 'Flexibler Produktionsmitarbeiter mit Erfahrung in Montage und Kommissionierung.',
            ],
            [
                'email' => 'candidate09@wannemueller.dev',
                'first_name' => 'Marta',
                'last_name' => 'Fernández',
                'occupation' => 'elektroniker',
                'country' => 'ES',
                'city' => 'Sevilla',
                'current_position' => 'Elektronikerin Messtechnik',
                'desired_position' => 'Elektronikerin',
                'experience' => 6,
                'skills' => ['elektronik', 'messtechnik', 'automatisierung'],
                'native_language' => 'es',
                'german_level' => 'B2',
                'summary' => 'Elektronikerin mit Schwerpunkt Messtechnik, Qualitätssicherung und Automatisierung.',
            ],
            [
                'email' => 'candidate10@wannemueller.dev',
                'first_name' => 'João',
                'last_name' => 'Costa',
                'occupation' => 'lkw-fahrer',
                'country' => 'PT',
                'city' => 'Porto',
                'current_position' => 'Gefahrgutfahrer',
                'desired_position' => 'LKW-Fahrer ADR',
                'experience' => 10,
                'skills' => ['ce', 'adr', 'gefahrgut'],
                'native_language' => 'pt',
                'german_level' => 'B1',
                'summary' => 'Erfahrener Gefahrgutfahrer mit CE, ADR und ausgeprägtem Sicherheitsbewusstsein.',
            ],
        ];

        $profiles = [];

        foreach ($definitions as $index => $definition) {
            $user = User::query()->updateOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['first_name'].' '.$definition['last_name'],
                    'email_verified_at' => now(),
                    'password' => 'password',
                    'role' => UserRole::Candidate,
                    'status' => UserStatus::Active,
                    'locale' => 'de',
                    'timezone' => 'Europe/Berlin',
                    'onboarding_completed_at' => now(),
                    'last_active_at' => now()->subMinutes(15 + ($index * 13)),
                ],
            );
            $occupation = Occupation::query()->where('slug', $definition['occupation'])->firstOrFail();
            $profile = CandidateProfile::query()->updateOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'occupation_id' => $occupation->getKey(),
                    'first_name' => $definition['first_name'],
                    'last_name' => $definition['last_name'],
                    'nationality_country_code' => $definition['country'],
                    'current_country_code' => $definition['country'],
                    'current_city' => $definition['city'],
                    'phone' => sprintf('+49 170 555 %04d', $index + 1),
                    'summary' => $definition['summary'],
                    'current_position' => $definition['current_position'],
                    'desired_position' => $definition['desired_position'],
                    'experience_years' => $definition['experience'],
                    'highest_qualification' => 'Abgeschlossene Berufsausbildung',
                    'driving_licenses' => $definition['occupation'] === 'lkw-fahrer' ? ['B', 'C', 'CE'] : ['B'],
                    'travel_ready' => true,
                    'relocation_ready' => true,
                    'available_from' => now()->addWeeks(4 + $index)->toDateString(),
                    'salary_expectation_cents' => 3_200_000 + ($definition['experience'] * 100_000),
                    'salary_currency' => 'EUR',
                    'employment_preferences' => ['full_time', 'permanent'],
                    'weekly_hours' => 40,
                    'requires_visa' => false,
                    'has_work_permit' => true,
                    'completeness' => 88,
                    'published_at' => now()->subDays($index + 1),
                ],
            );
            $skillIds = Skill::query()
                ->whereIn('slug', $definition['skills'])
                ->pluck('id')
                ->mapWithKeys(fn (int $id): array => [
                    $id => ['proficiency' => 4, 'experience_years' => $definition['experience'], 'is_verified' => false],
                ])
                ->all();
            $profile->skills()->sync($skillIds);

            $german = Language::query()->where('code', 'de')->firstOrFail();
            $native = Language::query()->where('code', $definition['native_language'])->firstOrFail();
            $profile->languages()->sync([
                $german->getKey() => ['level' => $definition['german_level'], 'is_verified' => false],
                $native->getKey() => ['level' => 'C1', 'is_verified' => true],
            ]);

            $profiles[$definition['email']] = $profile;
        }

        return $profiles;
    }

    /**
     * @param  array<string, array{company: Company, owner: User}>  $companies
     * @param  array<string, CandidateProfile>  $candidates
     */
    private function seedMarketplace(array $companies, array $candidates): void
    {
        $electrician = Occupation::query()->where('slug', 'elektriker')->firstOrFail();
        $driver = Occupation::query()->where('slug', 'lkw-fahrer')->firstOrFail();
        $german = Language::query()->where('code', 'de')->firstOrFail();

        $electricianJob = JobPosting::query()->updateOrCreate(
            [
                'company_id' => $companies['mueller']['company']->getKey(),
                'slug' => 'elektriker-schaltschrankbau',
            ],
            [
                'created_by' => $companies['mueller']['owner']->getKey(),
                'occupation_id' => $electrician->getKey(),
                'title' => 'Elektriker:in Schaltschrankbau',
                'position' => 'Elektriker:in',
                'description' => 'Wir suchen Verstärkung für Planung, Aufbau und Prüfung moderner Schaltschränke.',
                'expected_experience_years' => 2,
                'language_notes' => 'Deutsch ab B1',
                'hours_min' => 38,
                'hours_max' => 40,
                'employment_type' => 'full_time',
                'compensation_min_cents' => 4_000_000,
                'compensation_max_cents' => 4_800_000,
                'currency' => 'EUR',
                'compensation_interval' => 'year',
                'status' => JobStatus::Published,
                'visa_package_available' => true,
                'published_at' => now()->subDays(5),
            ],
        );
        $electricianJob->skills()->sync(
            Skill::query()->whereIn('slug', ['schaltschrankbau', 'industrie', 'siemens'])
                ->pluck('id')
                ->mapWithKeys(fn (int $id): array => [$id => ['importance' => 3]])
                ->all(),
        );
        $electricianJob->languages()->sync([
            $german->getKey() => ['minimum_level' => 'B1', 'is_required' => true],
        ]);
        $electricianJob->screeningQuestions()->updateOrCreate(
            ['sort_order' => 1],
            ['question' => 'Wann können Sie frühestens beginnen?', 'type' => 'text', 'is_required' => true],
        );

        $driverJob = JobPosting::query()->updateOrCreate(
            [
                'company_id' => $companies['rheincargo']['company']->getKey(),
                'slug' => 'lkw-fahrer-ce-adr',
            ],
            [
                'created_by' => $companies['rheincargo']['owner']->getKey(),
                'occupation_id' => $driver->getKey(),
                'title' => 'LKW-Fahrer:in CE / ADR',
                'position' => 'Berufskraftfahrer:in',
                'description' => 'Planbare nationale und internationale Touren mit moderner Fahrzeugflotte.',
                'expected_experience_years' => 3,
                'language_notes' => 'Deutsch ab A2',
                'hours_min' => 40,
                'hours_max' => 45,
                'employment_type' => 'full_time',
                'compensation_min_cents' => 3_800_000,
                'compensation_max_cents' => 4_500_000,
                'currency' => 'EUR',
                'compensation_interval' => 'year',
                'status' => JobStatus::Published,
                'visa_package_available' => true,
                'published_at' => now()->subDays(3),
            ],
        );
        $driverJob->skills()->sync(
            Skill::query()->whereIn('slug', ['ce', 'adr', 'fernverkehr'])
                ->pluck('id')
                ->mapWithKeys(fn (int $id): array => [$id => ['importance' => 3]])
                ->all(),
        );
        $driverJob->languages()->sync([
            $german->getKey() => ['minimum_level' => 'A2', 'is_required' => true],
        ]);

        $applications = [
            [
                'job' => $electricianJob,
                'candidate' => $candidates['candidate01@wannemueller.dev'],
                'owner' => $companies['mueller']['owner'],
                'score' => 92,
            ],
            [
                'job' => $driverJob,
                'candidate' => $candidates['candidate02@wannemueller.dev'],
                'owner' => $companies['rheincargo']['owner'],
                'score' => 89,
            ],
        ];

        foreach ($applications as $definition) {
            $application = JobApplication::query()->updateOrCreate(
                [
                    'job_posting_id' => $definition['job']->getKey(),
                    'candidate_profile_id' => $definition['candidate']->getKey(),
                ],
                [
                    'status' => ApplicationStatus::InReview,
                    'cover_letter' => 'Ich möchte meine Erfahrung künftig in Deutschland einsetzen.',
                    'match_score' => $definition['score'],
                    'match_breakdown' => [
                        'occupation' => 25,
                        'skills' => 18,
                        'language' => 13,
                        'experience' => 10,
                        'employment' => 9,
                        'availability' => 5,
                        'salary' => 5,
                        'relocation' => 5,
                        'documents' => 0,
                    ],
                    'applied_at' => now()->subDays(2),
                    'identity_revealed_at' => now()->subDays(2),
                ],
            );
            ApplicationStatusHistory::query()->firstOrCreate(
                ['application_id' => $application->getKey(), 'to_status' => ApplicationStatus::InReview],
                [
                    'changed_by' => $definition['owner']->getKey(),
                    'from_status' => ApplicationStatus::New,
                    'created_at' => now(),
                ],
            );
        }

        $firstCandidate = $candidates['candidate01@wannemueller.dev'];
        $firstCompany = $companies['mueller']['company'];
        $firstOwner = $companies['mueller']['owner'];
        $firstApplication = JobApplication::query()
            ->where('job_posting_id', $electricianJob->getKey())
            ->where('candidate_profile_id', $firstCandidate->getKey())
            ->firstOrFail();
        $list = TalentList::query()->updateOrCreate(
            ['company_id' => $firstCompany->getKey(), 'name' => 'Elektriker NRW'],
            ['created_by' => $firstOwner->getKey(), 'description' => 'Interessante Fachkräfte für NRW'],
        );
        TalentListMember::query()->firstOrCreate(
            ['talent_list_id' => $list->getKey(), 'candidate_profile_id' => $firstCandidate->getKey()],
            ['added_by' => $firstOwner->getKey(), 'note' => 'Sehr guter Match Score'],
        );

        $conversation = Conversation::query()->firstOrCreate(
            ['company_id' => $firstCompany->getKey(), 'application_id' => $firstApplication->getKey()],
            ['type' => 'application', 'title' => $electricianJob->title, 'last_message_at' => now()],
        );
        $conversation->participants()->syncWithoutDetaching([
            $firstOwner->getKey(),
            $firstCandidate->user_id,
        ]);
        Message::query()->firstOrCreate(
            ['conversation_id' => $conversation->getKey(), 'sender_id' => $firstOwner->getKey()],
            ['type' => 'text', 'body' => 'Vielen Dank für Ihre Bewerbung. Wir prüfen Ihr Profil.'],
        );
    }
}
