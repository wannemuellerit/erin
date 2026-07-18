<?php

namespace Database\Seeders;

use App\Enums\CompanyMemberRole;
use App\Enums\CompanyStatus;
use App\Enums\JobStatus;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\JobPosting;
use App\Models\Occupation;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Seeder;
use LogicException;

class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (
            app()->isProduction()
            || ! app()->environment(['local', 'testing'])
            || ! config('app.demo_mode')
        ) {
            throw new LogicException('Browser-Testdaten dürfen nur lokal oder in Tests mit APP_DEMO_MODE angelegt werden.');
        }

        $support = User::query()->updateOrCreate(
            ['email' => 'support.e2e@wannemueller.dev'],
            [
                'name' => 'Erin E2E Support',
                'email_verified_at' => now(),
                'password' => 'password',
                'role' => UserRole::Support,
                'status' => UserStatus::Active,
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'onboarding_completed_at' => now(),
            ],
        );

        $candidate = User::query()->updateOrCreate(
            ['email' => 'onboarding.candidate@wannemueller.dev'],
            [
                'name' => 'E2E Kandidat',
                'email_verified_at' => now(),
                'password' => 'password',
                'role' => UserRole::Candidate,
                'status' => UserStatus::Active,
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'onboarding_completed_at' => null,
            ],
        );
        CandidateProfile::query()->updateOrCreate(
            ['user_id' => $candidate->getKey()],
            [
                'first_name' => 'E2E',
                'last_name' => 'Kandidat',
                'occupation_id' => null,
                'completeness' => 0,
                'published_at' => null,
            ],
        );

        $companyOwner = User::query()->updateOrCreate(
            ['email' => 'onboarding.company@wannemueller.dev'],
            [
                'name' => 'E2E Firmeninhaber',
                'email_verified_at' => now(),
                'password' => 'password',
                'role' => UserRole::Company,
                'status' => UserStatus::Active,
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'onboarding_completed_at' => null,
            ],
        );
        $onboardingCompany = Company::query()->updateOrCreate(
            ['slug' => 'e2e-onboarding-company'],
            [
                'name' => 'E2E Onboarding GmbH',
                'email' => $companyOwner->email,
                'status' => CompanyStatus::Pending,
                'current_plan_id' => null,
                'subscription_status' => null,
            ],
        );
        CompanyMembership::query()->updateOrCreate(
            [
                'company_id' => $onboardingCompany->getKey(),
                'user_id' => $companyOwner->getKey(),
            ],
            [
                'role' => CompanyMemberRole::Owner,
                'accepted_at' => now(),
            ],
        );

        $requester = User::query()
            ->where('email', 'candidate01@wannemueller.dev')
            ->firstOrFail();
        $ticket = SupportTicket::query()->updateOrCreate(
            ['number' => 'ERIN-E2E-SUPPORT'],
            [
                'requester_id' => $requester->getKey(),
                'assigned_to' => $support->getKey(),
                'subject' => 'Browsertest Supportansicht',
                'category' => 'technical',
                'priority' => 'normal',
                'status' => SupportTicketStatus::Open,
                'last_reply_at' => now(),
            ],
        );
        $ticket->messages()->firstOrCreate(
            ['body' => 'Bitte prüfen Sie die schreibgeschützte Supportansicht.'],
            ['author_id' => $requester->getKey(), 'is_internal' => false],
        );

        $foreignCompany = Company::query()
            ->where('slug', 'rheincargo-logistik')
            ->firstOrFail();
        $foreignOwner = User::query()
            ->where('email', 'unternehmen.rheincargo@wannemueller.dev')
            ->firstOrFail();
        $occupation = Occupation::query()->where('slug', 'lkw-fahrer')->firstOrFail();

        $foreignJob = JobPosting::query()->find(990002) ?? new JobPosting;
        $foreignJob->forceFill([
            'id' => 990002,
            'company_id' => $foreignCompany->getKey(),
            'created_by' => $foreignOwner->getKey(),
            'occupation_id' => $occupation->getKey(),
            'title' => 'Mandantenfremde E2E-Stelle',
            'slug' => 'mandantenfremde-e2e-stelle',
            'position' => 'LKW-Fahrer:in',
            'description' => 'Dieser Datensatz prüft ausschließlich die Mandantentrennung im Browsertest.',
            'employment_type' => 'full_time',
            'currency' => 'EUR',
            'compensation_interval' => 'year',
            'status' => JobStatus::Published,
            'published_at' => now(),
        ])->save();
    }
}
