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

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@wannemueller.dev'],
            [
                'name' => 'Wannemüller Admin',
                'email_verified_at' => now(),
                'password' => 'password',
                'role' => UserRole::SuperAdmin,
                'status' => UserStatus::Active,
                'locale' => 'de',
            ],
        );

        $owner = User::query()->updateOrCreate(
            ['email' => 'recruiting@mueller-elektro.example'],
            [
                'name' => 'Marie Müller',
                'email_verified_at' => now(),
                'password' => 'password',
                'role' => UserRole::Company,
                'status' => UserStatus::Active,
                'locale' => 'de',
            ],
        );

        $plan = Plan::query()->where('slug', 'business')->firstOrFail();
        $company = Company::query()->updateOrCreate(
            ['slug' => 'mueller-elektrotechnik'],
            [
                'current_plan_id' => $plan->id,
                'name' => 'Müller Elektrotechnik',
                'legal_name' => 'Müller Elektrotechnik GmbH',
                'email' => $owner->email,
                'website' => 'https://mueller-elektro.example',
                'industry' => 'Elektrotechnik',
                'employee_count' => 85,
                'country_code' => 'DE',
                'city' => 'Düsseldorf',
                'description' => 'Moderner Elektrotechnikbetrieb mit internationalen Teams.',
                'benefits' => [
                    'accommodation' => true,
                    'language_course' => true,
                    'visa_support' => true,
                    'workwear' => true,
                ],
                'status' => CompanyStatus::Active,
                'subscription_status' => 'active',
                'subscription_started_at' => now()->startOfDay(),
                'subscription_renews_at' => now()->addMonths(4)->startOfDay(),
                'cancel_at_period_end' => false,
                'last_active_at' => now(),
            ],
        );

        CompanyMembership::query()->updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $owner->id],
            ['role' => CompanyMemberRole::Owner, 'accepted_at' => now()],
        );

        $candidateUser = User::query()->updateOrCreate(
            ['email' => 'candidate@wannemueller.dev'],
            [
                'name' => 'Anna Kowalska',
                'email_verified_at' => now(),
                'password' => 'password',
                'role' => UserRole::Candidate,
                'status' => UserStatus::Active,
                'locale' => 'de',
            ],
        );

        $occupation = Occupation::query()->where('slug', 'elektriker')->firstOrFail();
        $candidate = CandidateProfile::query()->updateOrCreate(
            ['user_id' => $candidateUser->id],
            [
                'occupation_id' => $occupation->id,
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'birth_date' => '1992-06-12',
                'nationality_country_code' => 'PL',
                'current_country_code' => 'PL',
                'current_city' => 'Wrocław',
                'phone' => '+48 555 010 010',
                'summary' => 'Erfahrene Elektrikerin mit Schwerpunkt Industrie und Schaltschrankbau.',
                'current_position' => 'Industrieelektrikerin',
                'desired_position' => 'Elektrikerin',
                'experience_years' => 8,
                'highest_qualification' => 'Technische Berufsausbildung',
                'driving_licenses' => ['B'],
                'travel_ready' => true,
                'relocation_ready' => true,
                'available_from' => now()->addMonth()->toDateString(),
                'salary_expectation_cents' => 42_000_00,
                'weekly_hours' => 40,
                'requires_visa' => false,
                'has_work_permit' => true,
                'completeness' => 87,
                'published_at' => now(),
            ],
        );

        $candidate->skills()->syncWithoutDetaching(
            Skill::query()->whereIn('slug', ['schaltschrankbau', 'industrie', 'siemens'])
                ->pluck('id')
                ->mapWithKeys(fn (int $id): array => [$id => ['proficiency' => 4, 'experience_years' => 6]])
                ->all(),
        );
        $candidate->languages()->syncWithoutDetaching([
            Language::query()->where('code', 'de')->value('id') => ['level' => 'B1', 'is_verified' => true],
            Language::query()->where('code', 'pl')->value('id') => ['level' => 'C1', 'is_verified' => true],
        ]);

        $job = JobPosting::query()->updateOrCreate(
            ['company_id' => $company->id, 'slug' => 'elektriker-schaltschrankbau'],
            [
                'created_by' => $owner->id,
                'occupation_id' => $occupation->id,
                'title' => 'Elektriker:in Schaltschrankbau',
                'position' => 'Elektriker:in',
                'description' => 'Wir suchen Verstärkung für Planung, Aufbau und Prüfung moderner Schaltschränke.',
                'expected_experience_years' => 2,
                'language_notes' => 'Deutsch ab B1',
                'hours_min' => 38,
                'hours_max' => 40,
                'employment_type' => 'full_time',
                'compensation_min_cents' => 40_000_00,
                'compensation_max_cents' => 48_000_00,
                'currency' => 'EUR',
                'compensation_interval' => 'year',
                'status' => JobStatus::Published,
                'visa_package_available' => true,
                'published_at' => now()->subDays(5),
            ],
        );
        $job->skills()->syncWithoutDetaching(
            $candidate->skills->pluck('id')->mapWithKeys(fn (int $id): array => [$id => ['importance' => 3]])->all(),
        );
        $job->languages()->syncWithoutDetaching([
            Language::query()->where('code', 'de')->value('id') => ['minimum_level' => 'B1', 'is_required' => true],
        ]);
        $job->screeningQuestions()->updateOrCreate(
            ['sort_order' => 1],
            ['question' => 'Wann können Sie frühestens beginnen?', 'type' => 'text', 'is_required' => true],
        );

        $application = JobApplication::query()->updateOrCreate(
            ['job_posting_id' => $job->id, 'candidate_profile_id' => $candidate->id],
            [
                'status' => ApplicationStatus::InReview,
                'cover_letter' => 'Ich möchte meine Erfahrung künftig in Deutschland einsetzen.',
                'match_score' => 92,
                'match_breakdown' => [
                    'occupation' => 25,
                    'skills' => 18,
                    'language' => 15,
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
            ['application_id' => $application->id, 'to_status' => ApplicationStatus::InReview],
            ['changed_by' => $owner->id, 'from_status' => ApplicationStatus::New, 'created_at' => now()],
        );

        $list = TalentList::query()->updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Elektriker NRW'],
            ['created_by' => $owner->id, 'description' => 'Interessante Fachkräfte für NRW'],
        );
        TalentListMember::query()->firstOrCreate(
            ['talent_list_id' => $list->id, 'candidate_profile_id' => $candidate->id],
            ['added_by' => $owner->id, 'note' => 'Sehr guter Match Score'],
        );

        $conversation = Conversation::query()->firstOrCreate(
            ['company_id' => $company->id, 'application_id' => $application->id],
            ['type' => 'application', 'title' => $job->title, 'last_message_at' => now()],
        );
        $conversation->participants()->syncWithoutDetaching([$owner->id, $candidateUser->id]);
        Message::query()->firstOrCreate(
            ['conversation_id' => $conversation->id, 'sender_id' => $owner->id],
            ['type' => 'text', 'body' => 'Vielen Dank für Ihre Bewerbung. Wir prüfen Ihr Profil.'],
        );

        $admin->forceFill(['last_active_at' => now()])->save();
    }
}
