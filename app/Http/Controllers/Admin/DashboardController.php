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
use App\Models\ActivityEntry;
use App\Models\AuditLog;
use App\Models\CandidateDocument;
use App\Models\Company;
use App\Models\IntegrationReceipt;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Referral;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\VisaCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends AdminController
{
    public function __invoke(Request $request): Response
    {
        $isSuperAdmin = $request->user()?->role === UserRole::SuperAdmin;
        $monthlyRecurringRevenue = $isSuperAdmin
            ? (int) Company::query()
                ->where('subscription_status', 'active')
                ->join('plans', 'plans.id', '=', 'companies.current_plan_id')
                ->sum(DB::raw('coalesce(plans.price_cents, 0) / greatest(coalesce(plans.term_months, 1), 1)'))
            : null;

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
                    'failed_webhooks_24h' => IntegrationReceipt::query()
                        ->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
                    'pending_webhooks' => IntegrationReceipt::query()->where('status', 'pending')->count(),
                    'oldest_pending_webhook_minutes' => (int) max(0, now()->diffInMinutes(
                        IntegrationReceipt::query()->where('status', 'pending')->min('created_at') ?? now(),
                    )),
                    'event_lag_minutes' => (int) max(0, now()->diffInMinutes(
                        ActivityEntry::query()->max('occurred_at') ?? now(),
                    )),
                    'external_notification_failures_24h' => DB::table('external_notification_deliveries')
                        ->whereIn('status', ['failed', 'rate_limited'])
                        ->where('created_at', '>=', now()->subDay())->count(),
                    'external_notification_cost_micros_month' => (int) DB::table('external_notification_deliveries')
                        ->where('created_at', '>=', now()->startOfMonth())->sum('cost_micros'),
                ],
                'growth' => [
                    'activated_30d' => Company::query()
                        ->where('status', CompanyStatus::Active)
                        ->where('updated_at', '>=', now()->subDays(30))->count(),
                    'retained_90d' => Company::query()
                        ->where('status', CompanyStatus::Active)
                        ->where('created_at', '<=', now()->subDays(90))->count(),
                    'active_subscriptions' => Company::query()
                        ->whereIn('subscription_status', ['active', 'trialing'])->count(),
                ],
                'financial' => $isSuperAdmin ? [
                    'mrr_cents' => $monthlyRecurringRevenue,
                    'currency' => 'EUR',
                ] : null,
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
