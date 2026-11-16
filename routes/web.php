<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdCampaignController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\Auth\AdminBootstrapController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\Candidate\DocumentController as CandidateDocumentController;
use App\Http\Controllers\Candidate\MarketplaceController;
use App\Http\Controllers\Candidate\ProfileController as CandidateProfileController;
use App\Http\Controllers\CommunicationController;
use App\Http\Controllers\CompanyMediaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Employer\AnalyticsController as EmployerAnalyticsController;
use App\Http\Controllers\Employer\ApplicationController as EmployerApplicationController;
use App\Http\Controllers\Employer\BulkCandidateController as EmployerBulkCandidateController;
use App\Http\Controllers\Employer\CandidateController as EmployerCandidateController;
use App\Http\Controllers\Employer\CandidateImportController as EmployerCandidateImportController;
use App\Http\Controllers\Employer\JobController as EmployerJobController;
use App\Http\Controllers\Employer\PortalController as EmployerPortalController;
use App\Http\Controllers\Employer\ReminderController as EmployerReminderController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HealthMetricsController;
use App\Http\Controllers\Integrations\ExternalNotificationWebhookController;
use App\Http\Controllers\Integrations\LiveKitWebhookController;
use App\Http\Controllers\Integrations\MailDeliveryWebhookController;
use App\Http\Controllers\Integrations\PartnerWebhookController;
use App\Http\Controllers\Integrations\PayoutWebhookController;
use App\Http\Controllers\Integrations\ZammadWebhookController;
use App\Http\Controllers\InterviewController;
use App\Http\Controllers\JobMediaController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PartnerServiceController;
use App\Http\Controllers\PayoutAccountController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\SupportActionController;
use App\Http\Controllers\SupportAttachmentController;
use App\Http\Controllers\SupportChatController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::get('status', StatusController::class)->name('status');
Route::get('health/ready', HealthController::class)
    ->middleware('throttle:30,1')
    ->name('health.ready');
Route::get('health/metrics', HealthMetricsController::class)
    ->middleware('throttle:60,1')
    ->name('health.metrics');
Route::get('pricing', [BillingController::class, 'pricing'])->name('pricing');
Route::get('contact', [PublicPageController::class, 'contact'])->name('contact');
Route::get('datenschutz', [PublicPageController::class, 'privacy'])->name('legal.privacy');
Route::get('impressum', [PublicPageController::class, 'imprint'])->name('legal.imprint');
Route::get('agb', [PublicPageController::class, 'terms'])->name('legal.terms');
Route::post('locale', [AccountController::class, 'locale'])
    ->middleware('throttle:20,1')
    ->name('locale.update');
Route::middleware(['guest', 'throttle:10,1'])
    ->prefix('bootstrap-admin')
    ->name('admin-bootstrap.')
    ->group(function (): void {
        Route::get('{token}', [AdminBootstrapController::class, 'show'])->name('show');
        Route::post('{token}', [AdminBootstrapController::class, 'store'])->name('store');
    });
Route::get('r/{code}', [ReferralController::class, 'track'])->name('referrals.track');
Route::get('join/{token}', [EmployerPortalController::class, 'trackInvitation'])->name('company-invitations.track');
Route::post('integrations/zammad/webhook', ZammadWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('integrations.zammad.webhook');
Route::post('integrations/mail/{provider}/webhook', MailDeliveryWebhookController::class)
    ->whereIn('provider', ['postmark', 'resend'])
    ->middleware('throttle:240,1')
    ->name('integrations.mail.webhook');
Route::post('integrations/external-notifications/{provider}/webhook', ExternalNotificationWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('integrations.external-notifications.webhook');
Route::post('integrations/partners/{organization:slug}/webhook', PartnerWebhookController::class)
    ->middleware('throttle:120,1')->name('integrations.partners.webhook');
Route::post('integrations/payouts/{provider}/webhook', PayoutWebhookController::class)
    ->middleware('throttle:120,1')->name('integrations.payouts.webhook');
Route::post('integrations/livekit/webhook', LiveKitWebhookController::class)
    ->middleware('throttle:240,1')->name('integrations.livekit.webhook');

Route::middleware(['auth', 'verified', 'staff.2fa'])->group(function (): void {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::put('onboarding/candidate', [OnboardingController::class, 'candidate'])
        ->name('onboarding.candidate');
    Route::put('onboarding/candidate/steps/{step}', [OnboardingController::class, 'candidateStep'])
        ->whereNumber('step')
        ->name('onboarding.candidate.step');
    Route::post('onboarding/candidate/photo', [CandidateProfileController::class, 'uploadPhoto'])
        ->middleware(['role:candidate', 'capability:candidate.profile.manage'])
        ->name('onboarding.candidate.photo');
    Route::post('onboarding/candidate/documents', [CandidateDocumentController::class, 'store'])
        ->middleware(['role:candidate', 'capability:candidate.profile.manage'])
        ->name('onboarding.candidate.documents');
    Route::put('onboarding/company', [OnboardingController::class, 'company'])
        ->name('onboarding.company');
    Route::put('onboarding/company/steps/{step}', [OnboardingController::class, 'companyStep'])
        ->whereNumber('step')
        ->name('onboarding.company.step');

    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('search', GlobalSearchController::class)
        ->middleware('throttle:60,1')
        ->name('search');

    Route::post('companies/{company}/activate', [AccountController::class, 'activateCompany'])
        ->name('companies.activate');
    Route::post('notifications/read-all', [AccountController::class, 'readAllNotifications'])
        ->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [AccountController::class, 'readNotification'])
        ->name('notifications.read');
    Route::post('ads/{campaign}/impression', [AdCampaignController::class, 'impression'])
        ->middleware('throttle:120,1')
        ->name('ads.impression');
    Route::post('ads/{campaign}/click', [AdCampaignController::class, 'click'])
        ->middleware('throttle:120,1')
        ->name('ads.click');
    Route::get('ads/{campaign}/media', [AdCampaignController::class, 'media'])
        ->middleware(['signed', 'throttle:120,1'])
        ->name('ads.media');

    Route::middleware('feature:messaging')->group(function (): void {
        Route::get('messages', [CommunicationController::class, 'index'])->name('messages.index');
        Route::post('messages/applications/{application}', [CommunicationController::class, 'start'])
            ->middleware('capability:messages.manage')
            ->name('messages.start');
        Route::post('messages/{conversation}', [CommunicationController::class, 'send'])
            ->middleware('capability:messages.manage')
            ->name('messages.send');
        Route::post('messages/{conversation}/read', [CommunicationController::class, 'read'])
            ->middleware('capability:messages.view')
            ->name('messages.read');
        Route::get('messages/attachments/{attachment}', [CommunicationController::class, 'downloadAttachment'])
            ->middleware(['signed', 'throttle:60,1'])
            ->name('messages.attachments.download');
    });

    Route::middleware('feature:interviews')->group(function (): void {
        Route::get('interviews', [InterviewController::class, 'index'])->name('interviews.index');
        Route::post('interviews/applications/{application}', [InterviewController::class, 'propose'])
            ->middleware('capability:interviews.manage')
            ->name('interviews.propose');
        Route::post('interviews/{interview}/respond', [InterviewController::class, 'respond'])
            ->middleware('capability:interviews.manage')
            ->name('interviews.respond');
        Route::post('interviews/{interview}/token', [InterviewController::class, 'token'])
            ->middleware('throttle:30,1')
            ->name('interviews.token');
        Route::get('interviews/{interview}/calendar.ics', [InterviewController::class, 'ics'])
            ->middleware('signed')
            ->name('interviews.ics');
        Route::put('availability', [InterviewController::class, 'updateAvailability'])
            ->middleware('capability:interviews.manage')
            ->name('availability.update');
    });

    Route::get('documents/{document}/download', [DocumentController::class, 'download'])
        ->middleware(['signed', 'throttle:60,1'])
        ->name('documents.download');
    Route::get('jobs/media/{media}/download', [JobMediaController::class, 'download'])
        ->middleware(['signed', 'throttle:30,1'])
        ->name('jobs.media.download');
    Route::get('companies/media/{media}/download', [CompanyMediaController::class, 'download'])
        ->middleware(['signed', 'throttle:60,1'])
        ->name('companies.media.download');
    Route::post('documents/{document}/applications/{application}/grant', [DocumentController::class, 'grant'])
        ->name('documents.grant');
    Route::delete('documents/{document}/applications/{application}/grant', [DocumentController::class, 'revoke'])
        ->name('documents.grant.revoke');

    Route::middleware('feature:support')->group(function (): void {
        Route::get('support', [SupportActionController::class, 'index'])->name('support.index');
        Route::post('support/tickets', [SupportActionController::class, 'createTicket'])
            ->middleware('capability:support.use')
            ->name('support.tickets.store');
        Route::post('support/tickets/{ticket}/reply', [SupportActionController::class, 'replyTicket'])
            ->middleware('capability:support.use')
            ->name('support.tickets.reply');
        Route::get('support/attachments/{attachment}', SupportAttachmentController::class)
            ->middleware(['signed', 'throttle:60,1'])
            ->name('support.attachments.download');
        Route::middleware(['feature:support_chatbot', 'capability:support.use'])->group(function (): void {
            Route::post('support/chat/sessions', [SupportChatController::class, 'store'])
                ->middleware('throttle:10,1')->name('support.chat.sessions.store');
            Route::post('support/chat/sessions/{session}/messages', [SupportChatController::class, 'message'])
                ->middleware('throttle:20,1')->name('support.chat.messages.store');
            Route::patch('support/chat/sessions/{session}/messages/{message}/feedback', [SupportChatController::class, 'feedback'])
                ->name('support.chat.messages.feedback');
            Route::post('support/chat/sessions/{session}/handoff', [SupportChatController::class, 'handoff'])
                ->middleware('throttle:5,1')->name('support.chat.handoff');
            Route::get('support/chat/sessions/{session}/export', [SupportChatController::class, 'export'])
                ->middleware('throttle:10,1')->name('support.chat.export');
            Route::delete('support/chat/sessions/{session}', [SupportChatController::class, 'destroy'])
                ->name('support.chat.destroy');
        });
    });
    Route::post('applications/{application}/feedback', [SupportActionController::class, 'feedback'])
        ->name('feedback.store');

    Route::get('referrals', [ReferralController::class, 'index'])->name('referrals.index');
    Route::post('referrals', [ReferralController::class, 'create'])
        ->middleware('capability:referrals.manage')->name('referrals.create');
    Route::post('referrals/email', [ReferralController::class, 'email'])
        ->middleware('capability:referrals.manage')->name('referrals.email');
    Route::post('referrals/payout-account', [PayoutAccountController::class, 'store'])
        ->middleware(['capability:referrals.manage', 'throttle:5,1'])->name('referrals.payout-account.store');
    Route::delete('referrals/payout-account/{account}', [PayoutAccountController::class, 'destroy'])
        ->middleware('capability:referrals.manage')->name('referrals.payout-account.destroy');

    Route::post('ai/run', [AiController::class, 'run'])
        ->middleware(['feature:ai', 'capability:candidate.ai.use,recruiting.ai.use', 'throttle:20,1'])->name('ai.run');
    Route::post('ai/runs/{run}/review', [AiController::class, 'review'])
        ->middleware(['feature:ai', 'capability:candidate.ai.use,recruiting.ai.use'])->name('ai.runs.review');
    Route::post('ai/consents', [AiController::class, 'grantConsent'])
        ->middleware(['feature:ai', 'capability:candidate.ai.use,recruiting.ai.use'])->name('ai.consents.store');
    Route::delete('ai/consents/{consent}', [AiController::class, 'withdrawConsent'])
        ->middleware(['feature:ai', 'capability:candidate.ai.use,recruiting.ai.use'])->name('ai.consents.destroy');
    Route::post('messages/items/{message}/translations', [CommunicationController::class, 'translate'])
        ->middleware(['feature:messaging', 'feature:ai', 'capability:candidate.ai.use,recruiting.ai.use', 'throttle:20,1'])
        ->name('messages.translate');

    Route::get('company-invitations/{token}/accept', [EmployerPortalController::class, 'acceptInvitation'])
        ->name('company-invitations.accept');
});

Route::middleware(['auth', 'verified', 'role:company', 'company.member', 'onboarding.complete'])
    ->prefix('employer')
    ->name('employer.')
    ->group(function (): void {
        Route::get('billing', [BillingController::class, 'show'])
            ->middleware(['feature:billing', 'capability:billing.view'])->name('billing');
        Route::patch('billing/details', [BillingController::class, 'updateDetails'])
            ->middleware(['feature:billing', 'capability:billing.manage'])->name('billing.details');
        Route::post('billing/checkout/{plan}', [BillingController::class, 'checkout'])
            ->middleware(['feature:billing', 'capability:billing.manage', 'throttle:10,1'])
            ->name('billing.checkout');
        Route::get('billing/success', [BillingController::class, 'success'])
            ->middleware('feature:billing')->name('billing.success');
        Route::post('billing/portal', [BillingController::class, 'portal'])
            ->middleware(['feature:billing', 'capability:billing.manage', 'throttle:10,1'])
            ->name('billing.portal');
        Route::post('billing/change/{plan}', [BillingController::class, 'changePlan'])
            ->middleware(['feature:billing', 'capability:billing.manage', 'throttle:10,1'])
            ->name('billing.change');
        Route::post('billing/cancel', [BillingController::class, 'cancel'])
            ->middleware(['feature:billing', 'capability:billing.manage', 'throttle:10,1'])
            ->name('billing.cancel');
        Route::post('billing/visa-credits', [BillingController::class, 'buyVisaCredits'])
            ->middleware(['feature:billing', 'capability:billing.manage', 'throttle:5,1'])
            ->name('billing.visa-credits');
        Route::post('billing/seats', [BillingController::class, 'addSeats'])
            ->middleware(['feature:billing', 'capability:billing.manage', 'throttle:5,1'])
            ->name('billing.seats');

        Route::middleware('company.subscribed')->group(function (): void {
            Route::get('candidates', [EmployerCandidateController::class, 'index'])
                ->middleware('capability:candidates.view')->name('candidates.index');
            Route::post('candidates/bulk/invite', [EmployerBulkCandidateController::class, 'invite'])
                ->middleware('capability:candidates.manage')
                ->name('candidates.bulk.invite');
            Route::post('candidates/bulk/message', [EmployerBulkCandidateController::class, 'message'])
                ->middleware('capability:candidates.manage')
                ->name('candidates.bulk.message');
            Route::post('candidate-bulk-batches/{batch}/cancel', [EmployerBulkCandidateController::class, 'cancel'])
                ->middleware('capability:candidates.manage')->name('candidate-bulk-batches.cancel');
            Route::get('candidate-bulk-batches/{batch}/report', [EmployerBulkCandidateController::class, 'report'])
                ->middleware('capability:candidates.manage')->name('candidate-bulk-batches.report');
            Route::get('candidates/{candidate}', [EmployerCandidateController::class, 'show'])
                ->middleware('capability:candidates.view')->name('candidates.show');
            Route::post('candidates/{candidate}/invite', [EmployerCandidateController::class, 'invite'])
                ->middleware('capability:candidates.manage')
                ->name('candidates.invite');
            Route::post('candidates/{candidate}/talent-list', [EmployerCandidateController::class, 'saveToTalentList'])
                ->middleware('capability:candidates.manage')
                ->name('candidates.talent-list');
            Route::delete('candidates/{candidate}/talent-list/{talentList}', [EmployerCandidateController::class, 'removeFromTalentList'])
                ->middleware('capability:candidates.manage')
                ->name('candidates.talent-list.remove');
            Route::post('candidate-saved-searches', [EmployerCandidateController::class, 'storeSavedSearch'])
                ->middleware('capability:candidates.manage')
                ->name('candidate-saved-searches.store');
            Route::delete('candidate-saved-searches/{savedSearch}', [EmployerCandidateController::class, 'destroySavedSearch'])
                ->middleware('capability:candidates.manage')
                ->name('candidate-saved-searches.destroy');
            Route::post('talent-lists', [EmployerCandidateController::class, 'storeTalentList'])
                ->middleware('capability:candidates.manage')
                ->name('talent-lists.store');
            Route::patch('talent-lists/{talentList}', [EmployerCandidateController::class, 'updateTalentList'])
                ->middleware('capability:candidates.manage')
                ->name('talent-lists.update');
            Route::delete('talent-lists/{talentList}', [EmployerCandidateController::class, 'destroyTalentList'])
                ->middleware('capability:candidates.manage')
                ->name('talent-lists.destroy');

            Route::get('productivity', function (): never {
                abort(404);
            })->name('productivity');
            Route::post('reminders', [EmployerReminderController::class, 'store'])
                ->middleware('capability:productivity.manage')->name('reminders.store');
            Route::patch('reminders/{reminder}', [EmployerReminderController::class, 'update'])
                ->middleware('capability:productivity.manage')
                ->name('reminders.update');
            Route::delete('reminders/{reminder}', [EmployerReminderController::class, 'destroy'])
                ->middleware('capability:productivity.manage')
                ->name('reminders.destroy');
            Route::post('candidate-imports', [EmployerCandidateImportController::class, 'store'])
                ->middleware('capability:candidates.manage')
                ->name('candidate-imports.store');
            Route::patch('candidate-imports/{candidateImport}/mapping', [EmployerCandidateImportController::class, 'map'])
                ->middleware('capability:candidates.manage')
                ->name('candidate-imports.map');
            Route::post('candidate-imports/{candidateImport}/cancel', [EmployerCandidateImportController::class, 'cancel'])
                ->middleware('capability:candidates.manage')
                ->name('candidate-imports.cancel');
            Route::delete('candidate-imports/{candidateImport}', [EmployerCandidateImportController::class, 'destroy'])
                ->middleware('capability:candidates.manage')
                ->name('candidate-imports.destroy');
            Route::get('candidate-imports/template.csv', [EmployerCandidateImportController::class, 'template'])
                ->name('candidate-imports.template');
            Route::get('analytics', EmployerAnalyticsController::class)
                ->middleware(['feature:analytics', 'capability:analytics.view'])->name('analytics');
            Route::get('analytics/export', [EmployerAnalyticsController::class, 'export'])
                ->middleware(['feature:analytics', 'capability:analytics.view', 'throttle:10,1'])
                ->name('analytics.export');

            Route::get('jobs', [EmployerJobController::class, 'index'])->middleware('capability:jobs.view')->name('jobs.index');
            Route::get('jobs/create', [EmployerJobController::class, 'create'])->middleware('capability:jobs.manage')->name('jobs.create');
            Route::post('jobs', [EmployerJobController::class, 'store'])->middleware('capability:jobs.manage')->name('jobs.store');
            Route::get('jobs/{job}/edit', [EmployerJobController::class, 'edit'])->middleware('capability:jobs.manage')->name('jobs.edit');
            Route::put('jobs/{job}', [EmployerJobController::class, 'update'])->middleware('capability:jobs.manage')->name('jobs.update');
            Route::post('jobs/{job}/duplicate', [EmployerJobController::class, 'duplicate'])->middleware('capability:jobs.manage')->name('jobs.duplicate');
            Route::delete('jobs/{job}', [EmployerJobController::class, 'destroy'])->middleware('capability:jobs.manage')->name('jobs.destroy');
            Route::delete('jobs/{job}/media/{media}', [EmployerJobController::class, 'destroyMedia'])->middleware('capability:jobs.manage')->name('jobs.media.destroy');
            Route::patch('jobs/{job}/status', [EmployerJobController::class, 'transition'])->middleware('capability:jobs.manage')->name('jobs.status');
            Route::post('jobs/{job}/boost', [EmployerJobController::class, 'boost'])->middleware('capability:jobs.manage')->name('jobs.boost');

            Route::get('pipeline', [EmployerApplicationController::class, 'pipeline'])->middleware('capability:applications.view')->name('pipeline');
            Route::patch('applications/{application}/status', [EmployerApplicationController::class, 'updateStatus'])
                ->middleware('capability:applications.manage')
                ->name('applications.status');
            Route::put('applications/{application}/candidate-review', [EmployerApplicationController::class, 'reviewCandidate'])
                ->middleware('capability:applications.manage')
                ->name('applications.candidate-review');

            Route::get('messages', [CommunicationController::class, 'index'])
                ->middleware('feature:messaging')->name('messages');
            Route::get('interviews', [InterviewController::class, 'index'])
                ->middleware('feature:interviews')->name('interviews');
            Route::get('visa', [EmployerPortalController::class, 'visa'])->middleware('capability:visa.view')->name('visa');
            Route::patch('visa/steps/{step}', [EmployerPortalController::class, 'updateVisaStep'])->middleware('capability:visa.manage')->name('visa.steps');
            Route::get('referrals', [ReferralController::class, 'index'])->name('referrals');
            Route::get('services', [PartnerServiceController::class, 'index'])->name('services.index');
            Route::post('services', [PartnerServiceController::class, 'store'])->name('services.store');
            Route::get('company', [EmployerPortalController::class, 'companyProfile'])->middleware('capability:company.view')->name('company');
            Route::put('company', [EmployerPortalController::class, 'updateCompanyProfile'])->middleware('capability:company.manage')->name('company.update');
            Route::get('team', [EmployerPortalController::class, 'team'])->middleware('capability:team.view')->name('team');
            Route::post('team/invitations', [EmployerPortalController::class, 'inviteTeamMember'])->middleware('capability:team.manage')->name('team.invite');
            Route::post('team/invitations/{invitation}/resend', [EmployerPortalController::class, 'resendTeamInvitation'])
                ->middleware('capability:team.manage')->name('team.invitations.resend');
            Route::delete('team/invitations/{invitation}', [EmployerPortalController::class, 'revokeTeamInvitation'])
                ->middleware('capability:team.manage')->name('team.invitations.revoke');
            Route::patch('team/members/{membership}', [EmployerPortalController::class, 'updateTeamMember'])
                ->middleware('capability:team.manage')->name('team.members.update');
            Route::delete('team/members/{membership}', [EmployerPortalController::class, 'removeTeamMember'])
                ->middleware('capability:team.manage')
                ->name('team.remove');
            Route::post('team/teams', [EmployerPortalController::class, 'storeTeam'])
                ->middleware('capability:team.manage')->name('team.teams.store');
            Route::put('team/teams/{team}', [EmployerPortalController::class, 'updateTeam'])
                ->middleware('capability:team.manage')->name('team.teams.update');
            Route::delete('team/teams/{team}', [EmployerPortalController::class, 'destroyTeam'])
                ->middleware('capability:team.manage')->name('team.teams.destroy');
            Route::patch('team/jobs/{job}/organization', [EmployerPortalController::class, 'assignJobOrganization'])
                ->middleware('capability:team.manage')->name('team.jobs.organization');
            Route::patch('team/applications/{application}/organization', [EmployerPortalController::class, 'assignApplicationOrganization'])
                ->middleware('capability:team.manage')->name('team.applications.organization');
            Route::post('team/members/{membership}/transfer-ownership', [EmployerPortalController::class, 'transferOwnership'])
                ->middleware(['capability:team.ownership.transfer', 'password.confirm'])
                ->name('team.transfer-ownership');
        });
    });

Route::middleware(['auth', 'verified', 'role:candidate', 'onboarding.complete'])
    ->prefix('candidate')
    ->name('candidate.')
    ->group(function (): void {
        Route::get('jobs', [MarketplaceController::class, 'jobs'])->name('jobs');
        Route::get('jobs/{job}', [MarketplaceController::class, 'showJob'])->name('jobs.show');
        Route::post('jobs/{job}/apply', [MarketplaceController::class, 'apply'])->name('jobs.apply');
        Route::get('companies', [MarketplaceController::class, 'companies'])->name('companies');
        Route::get('companies/{company}', [MarketplaceController::class, 'showCompany'])->name('companies.show');
        Route::get('applications', [MarketplaceController::class, 'applications'])->name('applications');
        Route::post('applications/{application}/withdraw', [MarketplaceController::class, 'withdraw'])
            ->name('applications.withdraw');
        Route::post('invitations/{invitation}/respond', [MarketplaceController::class, 'respondToInvitation'])
            ->name('invitations.respond');
        Route::get('profile', [CandidateProfileController::class, 'show'])->name('profile');
        Route::put('profile', [CandidateProfileController::class, 'update'])->middleware('capability:candidate.profile.manage')->name('profile.update');
        Route::post('profile/photo', [CandidateProfileController::class, 'uploadPhoto'])
            ->middleware('capability:candidate.profile.manage')
            ->name('profile.photo.upload');
        Route::delete('profile/photo', [CandidateProfileController::class, 'deletePhoto'])
            ->middleware('capability:candidate.profile.manage')
            ->name('profile.photo.delete');
        Route::get('profile/photo', [CandidateProfileController::class, 'photo'])
            ->middleware('signed')
            ->name('profile.photo');
        Route::post('profile/documents', [CandidateDocumentController::class, 'store'])
            ->middleware('capability:candidate.profile.manage')
            ->name('profile.documents');
        Route::patch('profile/documents/{document}', [CandidateDocumentController::class, 'update'])
            ->middleware('capability:candidate.profile.manage')
            ->name('profile.documents.update');
        Route::post('profile/documents/{document}/replace', [CandidateDocumentController::class, 'replace'])
            ->middleware('capability:candidate.profile.manage')
            ->name('profile.documents.replace');
        Route::delete('profile/documents/{document}', [CandidateDocumentController::class, 'destroy'])
            ->middleware('capability:candidate.profile.manage')
            ->name('profile.documents.destroy');
        Route::post('profile/publish', [CandidateProfileController::class, 'publish'])->middleware('capability:candidate.profile.manage')->name('profile.publish');
        Route::get('messages', [CommunicationController::class, 'index'])
            ->middleware('feature:messaging')->name('messages');
        Route::get('interviews', [InterviewController::class, 'index'])
            ->middleware('feature:interviews')->name('interviews');
        Route::get('ai-studio', [AiController::class, 'studio'])
            ->middleware('feature:ai')->name('ai-studio');
        Route::get('referrals', [ReferralController::class, 'index'])->name('referrals');
        Route::get('services', [PartnerServiceController::class, 'index'])->name('services.index');
        Route::post('services', [PartnerServiceController::class, 'store'])->name('services.store');
        Route::post('services/{case}/transfer', [PartnerServiceController::class, 'transfer'])->name('services.transfer');
        Route::post('services/{case}/withdraw', [PartnerServiceController::class, 'withdraw'])->name('services.withdraw');
        Route::post('services/{case}/switch', [PartnerServiceController::class, 'switchOffering'])->name('services.switch');
        Route::post('services/{case}/artifacts', [PartnerServiceController::class, 'storeArtifact'])->name('services.artifacts.store');
        Route::post('services/{case}/grants', [PartnerServiceController::class, 'grantDocument'])->name('services.grants.store');
        Route::get('services/{case}/export', [PartnerServiceController::class, 'export'])->middleware('throttle:10,1')->name('services.export');
        Route::delete('services/{case}', [PartnerServiceController::class, 'destroy'])->name('services.destroy');
    });

Route::middleware(['auth', 'verified', 'role:partner'])
    ->prefix('partner')->name('partner.')->group(function (): void {
        Route::get('cases', [PartnerServiceController::class, 'index'])->middleware('capability:partner.cases.view')->name('cases.index');
        Route::patch('cases/{case}', [PartnerServiceController::class, 'update'])->middleware('capability:partner.cases.manage')->name('cases.update');
        Route::post('cases/{case}/artifacts', [PartnerServiceController::class, 'storeArtifact'])->middleware('capability:partner.cases.manage')->name('cases.artifacts.store');
    });

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';

// Browser GETs use the web stack so an Inertia navigation can retain the
// current session, locale and application shell. API misses are still
// identified by the exception handler and keep a machine-readable response.
Route::fallback(function (): never {
    abort(404);
});
