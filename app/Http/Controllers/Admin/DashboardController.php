<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CandidateDocumentStatus;
use App\Enums\CompanyStatus;
use App\Enums\JobStatus;
use App\Enums\ReferralStatus;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VisaCaseStatus;
use App\Models\AuditLog;
use App\Models\CandidateDocument;
use App\Models\Company;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Referral;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\VisaCase;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends AdminController
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/Dashboard', [
            'metrics' => [
                'users' => [
                    'total' => User::query()->count(),
                    'candidates' => User::query()->where('role', UserRole::Candidate)->count(),
                    'companies' => User::query()->where('role', UserRole::Company)->count(),
                    'platform_staff' => User::query()
                        ->whereIn('role', [UserRole::Support, UserRole::SuperAdmin])
                        ->count(),
                    'blocked' => User::query()->where('status', UserStatus::Blocked)->count(),
                ],
                'companies' => [
                    'total' => Company::query()->count(),
                    'active' => Company::query()->where('status', CompanyStatus::Active)->count(),
                    'blocked' => Company::query()->where('status', CompanyStatus::Blocked)->count(),
                    'past_due' => Company::query()->where('subscription_status', 'past_due')->count(),
                ],
                'marketplace' => [
                    'published_jobs' => JobPosting::query()->where('status', JobStatus::Published)->count(),
                    'applications' => JobApplication::query()->count(),
                ],
                'operations' => [
                    'documents_waiting' => CandidateDocument::query()
                        ->whereIn('status', [
                            CandidateDocumentStatus::Uploaded,
                            CandidateDocumentStatus::InReview,
                        ])
                        ->count(),
                    'visa_active' => VisaCase::query()
                        ->whereIn('status', [VisaCaseStatus::Active, VisaCaseStatus::Blocked])
                        ->count(),
                    'tickets_open' => SupportTicket::query()
                        ->whereNotIn('status', [
                            SupportTicketStatus::Resolved,
                            SupportTicketStatus::Closed,
                        ])
                        ->count(),
                    'referrals_payable' => Referral::query()
                        ->where('status', ReferralStatus::Approved)
                        ->count(),
                    'referrals_payable_cents' => (int) Referral::query()
                        ->where('status', ReferralStatus::Approved)
                        ->sum('commission_cents'),
                ],
            ],
            'recent_audit' => AuditLog::query()
                ->with('actor:id,name,email')
                ->latest('created_at')
                ->limit(12)
                ->get([
                    'id',
                    'actor_id',
                    'event',
                    'auditable_type',
                    'auditable_id',
                    'metadata',
                    'created_at',
                ]),
        ]);
    }
}
