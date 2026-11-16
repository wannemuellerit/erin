<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\CompanyStatus;
use App\Enums\InterviewStatus;
use App\Enums\JobStatus;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CandidateImport;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyBillingInvoice;
use App\Models\CompanyMembership;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Occupation;
use App\Models\SupportChatSession;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\VisaCase;
use App\Services\Activity\ActivityRecorder;
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
                'name' => 'Faden E2E Support',
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
                'onboarding_step' => 2,
                'onboarding_data' => null,
            ],
        );
        $candidateProfile = CandidateProfile::query()->updateOrCreate(
            ['user_id' => $candidate->getKey()],
            [
                'first_name' => 'E2E',
                'last_name' => 'Kandidat',
                'occupation_id' => null,
                'current_country_code' => null,
                'current_city' => null,
                'phone' => null,
                'whatsapp' => null,
                'summary' => null,
                'current_position' => null,
                'desired_position' => null,
                'experience_years' => 0,
                'relocation_ready' => false,
                'requires_visa' => true,
                'has_work_permit' => false,
                'completeness' => 0,
                'published_at' => null,
            ],
        );
        $candidateProfile->experiences()->delete();
        $candidateProfile->educations()->delete();
        $candidateProfile->skills()->detach();
        $candidateProfile->languages()->detach();
        $candidate->availabilitySlots()->delete();

        foreach (['pl', 'ro', 'hr', 'es', 'pt'] as $locale) {
            $localizedCandidate = User::query()->updateOrCreate(
                ['email' => "onboarding.{$locale}.candidate@wannemueller.dev"],
                [
                    'name' => "E2E {$locale} Candidate",
                    'email_verified_at' => now(),
                    'password' => 'password',
                    'role' => UserRole::Candidate,
                    'status' => UserStatus::Active,
                    'locale' => $locale,
                    'timezone' => 'Europe/Berlin',
                    'onboarding_completed_at' => null,
                    'onboarding_step' => 2,
                    'onboarding_data' => null,
                ],
            );
            $localizedProfile = CandidateProfile::query()->updateOrCreate(
                ['user_id' => $localizedCandidate->getKey()],
                [
                    'first_name' => 'E2E',
                    'last_name' => strtoupper($locale),
                    'occupation_id' => null,
                    'current_country_code' => null,
                    'current_city' => null,
                    'phone' => null,
                    'whatsapp' => null,
                    'summary' => null,
                    'current_position' => null,
                    'desired_position' => null,
                    'experience_years' => 0,
                    'relocation_ready' => false,
                    'requires_visa' => true,
                    'has_work_permit' => false,
                    'completeness' => 0,
                    'published_at' => null,
                ],
            );
            $localizedProfile->experiences()->delete();
            $localizedProfile->educations()->delete();
            $localizedProfile->skills()->detach();
            $localizedProfile->languages()->detach();
            $localizedCandidate->availabilitySlots()->delete();
        }

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
                'onboarding_step' => 2,
                'onboarding_data' => null,
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

        $roleCompany = Company::query()->where('slug', 'mueller-elektrotechnik')->firstOrFail();
        CompanyBillingInvoice::query()->updateOrCreate(
            ['stripe_invoice_id' => 'in_erin_e2e_paid'],
            [
                'company_id' => $roleCompany->getKey(),
                'number' => 'ERIN-E2E-2026-001',
                'status' => 'paid',
                'currency' => 'EUR',
                'subtotal_cents' => 10000,
                'discount_cents' => 1000,
                'tax_cents' => 1710,
                'total_cents' => 10710,
                'amount_paid_cents' => 10710,
                'amount_due_cents' => 0,
                'billing_reason' => 'subscription_cycle',
                'hosted_invoice_url' => 'https://invoice.stripe.com/i/erin-e2e',
                'invoice_pdf_url' => 'https://pay.stripe.com/invoice/erin-e2e/pdf',
                'promotion_codes' => ['ERIN10'],
                'customer_tax_ids' => [[
                    'type' => 'eu_vat',
                    'value' => 'DE123456789',
                ]],
                'billing_address' => [
                    'line1' => 'E2E Straße 1',
                    'postal_code' => '10115',
                    'city' => 'Berlin',
                    'country' => 'DE',
                ],
                'period_starts_at' => now()->subMonths(2),
                'period_ends_at' => now(),
                'issued_at' => now()->subDays(2),
                'paid_at' => now()->subDays(2),
                'stripe_event_created_at' => now()->subDays(2)->getTimestamp(),
            ],
        );
        foreach ([
            [
                'email' => 'company.admin.e2e@wannemueller.dev',
                'name' => 'Faden E2E Firmenadmin',
                'role' => CompanyMemberRole::Admin,
            ],
            [
                'email' => 'recruiter.e2e@wannemueller.dev',
                'name' => 'Faden E2E Recruiter',
                'role' => CompanyMemberRole::Recruiter,
            ],
            [
                'email' => 'viewer.e2e@wannemueller.dev',
                'name' => 'Faden E2E Viewer',
                'role' => CompanyMemberRole::Viewer,
            ],
        ] as $account) {
            $member = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'email_verified_at' => now(),
                    'password' => 'password',
                    'role' => UserRole::Company,
                    'status' => UserStatus::Active,
                    'locale' => 'de',
                    'timezone' => 'Europe/Berlin',
                    'onboarding_completed_at' => now(),
                ],
            );
            CompanyMembership::query()->updateOrCreate(
                [
                    'company_id' => $roleCompany->getKey(),
                    'user_id' => $member->getKey(),
                ],
                [
                    'role' => $account['role'],
                    'accepted_at' => now(),
                ],
            );
        }

        $requester = User::query()
            ->where('email', 'candidate01@wannemueller.dev')
            ->firstOrFail();
        SupportChatSession::query()->where('user_id', $requester->getKey())->delete();
        $browserJob = JobPosting::query()
            ->where('company_id', $roleCompany->getKey())
            ->where('slug', 'elektriker-schaltschrankbau')
            ->firstOrFail();
        $browserSearchJob = JobPosting::query()->find(990003) ?? new JobPosting;
        $browserSearchJob->forceFill([
            'id' => 990003,
            'company_id' => $roleCompany->getKey(),
            'created_by' => $browserJob->created_by,
            'occupation_id' => $browserJob->occupation_id,
            'title' => 'E2E Suchstelle Elektrotechnik',
            'slug' => 'e2e-suchstelle-elektrotechnik',
            'position' => 'Elektrotechniker:in',
            'description' => 'Stabiler Datensatz für Suche und Deep-Link-Abnahme.',
            'employment_type' => 'full_time',
            'currency' => 'EUR',
            'compensation_interval' => 'year',
            'status' => JobStatus::Published,
            'published_at' => now(),
        ])->save();
        JobApplication::query()
            ->where('job_posting_id', $browserSearchJob->getKey())
            ->where(
                'candidate_profile_id',
                $requester->candidateProfile()->firstOrFail()->getKey(),
            )
            ->delete();
        app(ActivityRecorder::class)->record(
            'job.created',
            User::query()->findOrFail($browserJob->created_by),
            $roleCompany,
            $browserSearchJob,
            ['job_title' => $browserSearchJob->title],
            idempotencyKey: 'browser-search-job-created',
        );

        $completedImport = CandidateImport::query()->updateOrCreate(
            [
                'company_id' => $roleCompany->getKey(),
                'original_filename' => 'e2e-import-erfolgreich.csv',
            ],
            [
                'created_by' => $browserJob->created_by,
                'disk' => 'private',
                'storage_path' => 'browser/e2e-import-erfolgreich.csv',
                'status' => 'completed',
                'total_rows' => 2,
                'imported_rows' => 2,
                'failed_rows' => 0,
                'mapping' => null,
                'errors' => null,
                'started_at' => now()->subMinutes(5),
                'cancellation_requested_at' => null,
                'cancelled_at' => null,
                'completed_at' => now()->subMinutes(4),
            ],
        );
        $completedImport->rows()->delete();

        $partialImport = CandidateImport::query()->updateOrCreate(
            [
                'company_id' => $roleCompany->getKey(),
                'original_filename' => 'e2e-import-teilfehler.csv',
            ],
            [
                'created_by' => $browserJob->created_by,
                'disk' => 'private',
                'storage_path' => 'browser/e2e-import-teilfehler.csv',
                'status' => 'completed_with_errors',
                'total_rows' => 2,
                'imported_rows' => 1,
                'failed_rows' => 1,
                'mapping' => null,
                'errors' => null,
                'started_at' => now()->subMinutes(3),
                'cancellation_requested_at' => null,
                'cancelled_at' => null,
                'completed_at' => now()->subMinutes(2),
            ],
        );
        $partialImport->rows()->updateOrCreate(
            ['row_number' => 2],
            [
                'company_id' => $roleCompany->getKey(),
                'email' => 'ungueltig',
                'status' => 'invalid',
                'payload' => ['email' => 'ungueltig'],
                'errors' => ['email' => ['Die E-Mail-Adresse ist ungültig.']],
            ],
        );

        $pendingImport = CandidateImport::query()->updateOrCreate(
            [
                'company_id' => $roleCompany->getKey(),
                'original_filename' => 'e2e-import-abbruch.csv',
            ],
            [
                'created_by' => $browserJob->created_by,
                'disk' => 'private',
                'storage_path' => 'browser/e2e-import-abbruch.csv',
                'status' => 'awaiting_mapping',
                'total_rows' => 2,
                'imported_rows' => 0,
                'failed_rows' => 0,
                'mapping' => [
                    'headers' => ['email', 'current_position'],
                    'preview' => [[
                        'email' => 'browser.import@example.test',
                        'current_position' => 'Elektriker:in',
                    ]],
                    'selection' => [
                        'email' => 'email',
                        'current_position' => 'current_position',
                    ],
                ],
                'errors' => null,
                'started_at' => null,
                'cancellation_requested_at' => null,
                'cancelled_at' => null,
                'completed_at' => null,
            ],
        );
        $pendingImport->rows()->delete();
        $browserApplication = JobApplication::query()->updateOrCreate(
            [
                'job_posting_id' => $browserJob->getKey(),
                'candidate_profile_id' => $requester->candidateProfile()->firstOrFail()->getKey(),
            ],
            [
                'status' => ApplicationStatus::InterviewScheduled,
                'applied_at' => now()->subDays(3),
            ],
        );
        Interview::query()
            ->where('application_id', $browserApplication->getKey())
            ->where('livekit_room_name', '!=', 'erin-e2e-livekit-room')
            ->delete();
        Interview::query()->updateOrCreate(
            ['livekit_room_name' => 'erin-e2e-livekit-room'],
            [
                'application_id' => $browserApplication->getKey(),
                'organizer_id' => $browserJob->created_by,
                'proposed_by' => $browserJob->created_by,
                'status' => InterviewStatus::Confirmed,
                'starts_at' => now()->subMinutes(5),
                'ends_at' => now()->addMinutes(45),
                'timezone' => 'Europe/Berlin',
                'confirmed_at' => now()->subHour(),
            ],
        );
        $visaCase = VisaCase::query()->updateOrCreate(
            ['application_id' => $browserApplication->getKey()],
            [
                'company_id' => $roleCompany->getKey(),
                'candidate_profile_id' => $requester->candidateProfile()->firstOrFail()->getKey(),
                'status' => 'active',
                'version' => 0,
                'blocked_reason' => null,
                'closed_reason' => null,
                'credit_status' => 'consumed',
                'credit_source' => 'browser_fixture',
                'assigned_to' => null,
                'started_at' => now()->subDays(3),
                'target_start_date' => today()->addMonths(2),
                'completed_at' => null,
            ],
        );
        foreach ([
            'registration', 'documents', 'review', 'employer', 'contract',
            'recognition', 'visa', 'flight', 'registration_de', 'work_start',
        ] as $index => $key) {
            $step = $visaCase->steps()->updateOrCreate(
                ['key' => $key],
                [
                    'title' => str($key)->headline(),
                    'status' => $index === 0 ? 'in_progress' : 'open',
                    'visibility' => 'shared',
                    'responsible_user_id' => $index === 0 ? $browserJob->created_by : null,
                    'due_at' => $index === 0 ? today()->addDays(5) : null,
                    'blocker' => null,
                    'completion_evidence' => null,
                    'completed_at' => null,
                ],
            );

            if ($index === 0) {
                $step->tasks()->updateOrCreate(
                    ['title' => 'E2E Visumunterlagen prüfen'],
                    [
                        'assigned_to' => $browserJob->created_by,
                        'created_by' => $browserJob->created_by,
                        'status' => 'open',
                        'visibility' => 'shared',
                        'due_at' => today()->addDays(2),
                    ],
                );
            }
        }
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
