<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\Auth\AdminBootstrapController;
use App\Http\Controllers\BillingController;
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
use App\Http\Controllers\Employer\ProductivityController as EmployerProductivityController;
use App\Http\Controllers\Employer\ReminderController as EmployerReminderController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HealthMetricsController;
use App\Http\Controllers\Integrations\ZammadWebhookController;
use App\Http\Controllers\InterviewController;
use App\Http\Controllers\JobMediaController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\SupportActionController;
use App\Http\Controllers\SupportAttachmentController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
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

Route::middleware(['auth', 'verified', 'staff.2fa'])->group(function (): void {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::put('onboarding/candidate', [OnboardingController::class, 'candidate'])
        ->name('onboarding.candidate');
    Route::put('onboarding/company', [OnboardingController::class, 'company'])
        ->name('onboarding.company');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::post('companies/{company}/activate', [AccountController::class, 'activateCompany'])
        ->name('companies.activate');
    Route::post('notifications/read-all', [AccountController::class, 'readAllNotifications'])
        ->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [AccountController::class, 'readNotification'])
        ->name('notifications.read');

    Route::get('messages', [CommunicationController::class, 'index'])->name('messages.index');
    Route::post('messages/applications/{application}', [CommunicationController::class, 'start'])
        ->name('messages.start');
    Route::post('messages/{conversation}', [CommunicationController::class, 'send'])
        ->name('messages.send');
    Route::post('messages/{conversation}/read', [CommunicationController::class, 'read'])
        ->name('messages.read');
    Route::get('messages/attachments/{attachment}', [CommunicationController::class, 'downloadAttachment'])
        ->middleware(['signed', 'throttle:60,1'])
        ->name('messages.attachments.download');

    Route::get('interviews', [InterviewController::class, 'index'])->name('interviews.index');
    Route::post('interviews/applications/{application}', [InterviewController::class, 'propose'])
        ->name('interviews.propose');
    Route::post('interviews/{interview}/respond', [InterviewController::class, 'respond'])
        ->name('interviews.respond');
    Route::post('interviews/{interview}/token', [InterviewController::class, 'token'])
        ->middleware('throttle:30,1')
        ->name('interviews.token');
    Route::get('interviews/{interview}/calendar.ics', [InterviewController::class, 'ics'])
        ->middleware('signed')
        ->name('interviews.ics');
    Route::put('availability', [InterviewController::class, 'updateAvailability'])
        ->name('availability.update');

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

    Route::get('support', [SupportActionController::class, 'index'])->name('support.index');
    Route::post('support/tickets', [SupportActionController::class, 'createTicket'])
        ->name('support.tickets.store');
    Route::post('support/tickets/{ticket}/reply', [SupportActionController::class, 'replyTicket'])
        ->name('support.tickets.reply');
    Route::get('support/attachments/{attachment}', SupportAttachmentController::class)
        ->middleware(['signed', 'throttle:60,1'])
        ->name('support.attachments.download');
    Route::post('applications/{application}/feedback', [SupportActionController::class, 'feedback'])
        ->name('feedback.store');

    Route::get('referrals', [ReferralController::class, 'index'])->name('referrals.index');
    Route::post('referrals', [ReferralController::class, 'create'])->name('referrals.create');
    Route::post('referrals/email', [ReferralController::class, 'email'])->name('referrals.email');

    Route::post('ai/run', [AiController::class, 'run'])->middleware('throttle:20,1')->name('ai.run');
    Route::post('ai/consents', [AiController::class, 'grantConsent'])->name('ai.consents.store');
    Route::delete('ai/consents/{consent}', [AiController::class, 'withdrawConsent'])->name('ai.consents.destroy');

    Route::get('company-invitations/{token}/accept', [EmployerPortalController::class, 'acceptInvitation'])
        ->name('company-invitations.accept');
});

Route::middleware(['auth', 'verified', 'role:company', 'company.member', 'onboarding.complete'])
    ->prefix('employer')
    ->name('employer.')
    ->group(function (): void {
        Route::get('billing', [BillingController::class, 'show'])->name('billing');
        Route::patch('billing/details', [BillingController::class, 'updateDetails'])->name('billing.details');
        Route::post('billing/checkout/{plan}', [BillingController::class, 'checkout'])
            ->middleware('throttle:10,1')
            ->name('billing.checkout');
        Route::get('billing/success', [BillingController::class, 'success'])->name('billing.success');
        Route::post('billing/portal', [BillingController::class, 'portal'])
            ->middleware('throttle:10,1')
            ->name('billing.portal');
        Route::post('billing/change/{plan}', [BillingController::class, 'changePlan'])
            ->middleware('throttle:10,1')
            ->name('billing.change');
        Route::post('billing/cancel', [BillingController::class, 'cancel'])
            ->middleware('throttle:10,1')
            ->name('billing.cancel');
        Route::post('billing/visa-credits', [BillingController::class, 'buyVisaCredits'])
            ->middleware('throttle:5,1')
            ->name('billing.visa-credits');
        Route::post('billing/seats', [BillingController::class, 'addSeats'])
            ->middleware('throttle:5,1')
            ->name('billing.seats');

        Route::middleware('company.subscribed')->group(function (): void {
            Route::get('candidates', [EmployerCandidateController::class, 'index'])->name('candidates.index');
            Route::post('candidates/bulk/invite', [EmployerBulkCandidateController::class, 'invite'])
                ->name('candidates.bulk.invite');
            Route::post('candidates/bulk/message', [EmployerBulkCandidateController::class, 'message'])
                ->name('candidates.bulk.message');
            Route::get('candidates/{candidate}', [EmployerCandidateController::class, 'show'])->name('candidates.show');
            Route::post('candidates/{candidate}/invite', [EmployerCandidateController::class, 'invite'])
                ->name('candidates.invite');
            Route::post('candidates/{candidate}/talent-list', [EmployerCandidateController::class, 'saveToTalentList'])
                ->name('candidates.talent-list');

            Route::get('productivity', EmployerProductivityController::class)->name('productivity');
            Route::post('reminders', [EmployerReminderController::class, 'store'])->name('reminders.store');
            Route::patch('reminders/{reminder}', [EmployerReminderController::class, 'update'])
                ->name('reminders.update');
            Route::delete('reminders/{reminder}', [EmployerReminderController::class, 'destroy'])
                ->name('reminders.destroy');
            Route::post('candidate-imports', [EmployerCandidateImportController::class, 'store'])
                ->name('candidate-imports.store');
            Route::patch('candidate-imports/{candidateImport}/mapping', [EmployerCandidateImportController::class, 'map'])
                ->name('candidate-imports.map');
            Route::delete('candidate-imports/{candidateImport}', [EmployerCandidateImportController::class, 'destroy'])
                ->name('candidate-imports.destroy');
            Route::get('candidate-imports/template.csv', [EmployerCandidateImportController::class, 'template'])
                ->name('candidate-imports.template');
            Route::get('analytics', EmployerAnalyticsController::class)->name('analytics');

            Route::get('jobs', [EmployerJobController::class, 'index'])->name('jobs.index');
            Route::get('jobs/create', [EmployerJobController::class, 'create'])->name('jobs.create');
            Route::post('jobs', [EmployerJobController::class, 'store'])->name('jobs.store');
            Route::get('jobs/{job}/edit', [EmployerJobController::class, 'edit'])->name('jobs.edit');
            Route::put('jobs/{job}', [EmployerJobController::class, 'update'])->name('jobs.update');
            Route::patch('jobs/{job}/status', [EmployerJobController::class, 'transition'])->name('jobs.status');
            Route::post('jobs/{job}/boost', [EmployerJobController::class, 'boost'])->name('jobs.boost');

            Route::get('pipeline', [EmployerApplicationController::class, 'pipeline'])->name('pipeline');
            Route::patch('applications/{application}/status', [EmployerApplicationController::class, 'updateStatus'])
                ->name('applications.status');
            Route::put('applications/{application}/candidate-review', [EmployerApplicationController::class, 'reviewCandidate'])
                ->name('applications.candidate-review');

            Route::get('messages', [CommunicationController::class, 'index'])->name('messages');
            Route::get('interviews', [InterviewController::class, 'index'])->name('interviews');
            Route::get('visa', [EmployerPortalController::class, 'visa'])->name('visa');
            Route::patch('visa/steps/{step}', [EmployerPortalController::class, 'updateVisaStep'])->name('visa.steps');
            Route::get('referrals', [ReferralController::class, 'index'])->name('referrals');
            Route::get('company', [EmployerPortalController::class, 'companyProfile'])->name('company');
            Route::put('company', [EmployerPortalController::class, 'updateCompanyProfile'])->name('company.update');
            Route::get('team', [EmployerPortalController::class, 'team'])->name('team');
            Route::post('team/invitations', [EmployerPortalController::class, 'inviteTeamMember'])->name('team.invite');
            Route::delete('team/members/{membership}', [EmployerPortalController::class, 'removeTeamMember'])
                ->name('team.remove');
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
        Route::put('profile', [CandidateProfileController::class, 'update'])->name('profile.update');
        Route::post('profile/photo', [CandidateProfileController::class, 'uploadPhoto'])
            ->name('profile.photo.upload');
        Route::delete('profile/photo', [CandidateProfileController::class, 'deletePhoto'])
            ->name('profile.photo.delete');
        Route::get('profile/photo', [CandidateProfileController::class, 'photo'])
            ->middleware('signed')
            ->name('profile.photo');
        Route::post('profile/documents', [CandidateProfileController::class, 'uploadDocument'])
            ->name('profile.documents');
        Route::post('profile/publish', [CandidateProfileController::class, 'publish'])->name('profile.publish');
        Route::get('messages', [CommunicationController::class, 'index'])->name('messages');
        Route::get('interviews', [InterviewController::class, 'index'])->name('interviews');
        Route::get('ai-studio', [AiController::class, 'studio'])->name('ai-studio');
        Route::get('referrals', [ReferralController::class, 'index'])->name('referrals');
    });

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
