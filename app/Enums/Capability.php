<?php

namespace App\Enums;

enum Capability: string
{
    case DashboardView = 'dashboard.view';
    case CandidateMarketplaceView = 'candidates.view';
    case CandidateMarketplaceManage = 'candidates.manage';
    case JobsView = 'jobs.view';
    case JobsManage = 'jobs.manage';
    case ApplicationsView = 'applications.view';
    case ApplicationsManage = 'applications.manage';
    case MessagesView = 'messages.view';
    case MessagesManage = 'messages.manage';
    case InterviewsView = 'interviews.view';
    case InterviewsManage = 'interviews.manage';
    case AnalyticsView = 'analytics.view';
    case ProductivityManage = 'productivity.manage';
    case VisaView = 'visa.view';
    case VisaManage = 'visa.manage';
    case ReferralsView = 'referrals.view';
    case ReferralsManage = 'referrals.manage';
    case CompanyView = 'company.view';
    case CompanyManage = 'company.manage';
    case TeamView = 'team.view';
    case TeamManage = 'team.manage';
    case OwnershipTransfer = 'team.ownership.transfer';
    case BillingView = 'billing.view';
    case BillingManage = 'billing.manage';
    case CandidateProfileManage = 'candidate.profile.manage';
    case CandidateJobsView = 'candidate.jobs.view';
    case CandidateApplicationsManage = 'candidate.applications.manage';
    case CandidateAiUse = 'candidate.ai.use';
    case RecruitingAiUse = 'recruiting.ai.use';
    case SupportUse = 'support.use';
    case PlatformView = 'platform.view';
    case PlatformSupportManage = 'platform.support.manage';
    case PlatformManage = 'platform.manage';
}
