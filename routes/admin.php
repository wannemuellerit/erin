<?php

use App\Http\Controllers\Admin\AccessListEntryController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\FeatureFlagController;
use App\Http\Controllers\Admin\GdprRequestController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\PlatformRoleController;
use App\Http\Controllers\Admin\ReferralController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SkillTaxonomyController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VisaController;
use App\Http\Controllers\Support\ImpersonationController;
use App\Http\Middleware\BlockSupportWrites;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:super_admin,support', 'staff.2fa'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::middleware('capability:platform.view')->group(function (): void {
            Route::get('/', DashboardController::class)->name('dashboard');
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
            Route::get('visa', [VisaController::class, 'index'])->name('visa.index');
            Route::get('support', [SupportController::class, 'index'])->name('support.index');
            Route::patch('support/{ticket}', [SupportController::class, 'update'])
                ->middleware('capability:platform.support.manage')
                ->name('support.update');
            Route::post('support/{ticket}/replies', [SupportController::class, 'reply'])
                ->middleware('capability:platform.support.manage')
                ->name('support.reply');
            Route::post('support/impersonate/{user}', [ImpersonationController::class, 'start'])
                ->middleware('capability:platform.support.manage')
                ->name('support.impersonation.start');
            Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
            Route::get('referrals', [ReferralController::class, 'index'])->name('referrals.index');
            Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
            Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
            Route::get('system', [SystemController::class, 'index'])->name('system.index');
        });

        Route::middleware(['role:super_admin', 'capability:platform.manage'])->group(function (): void {
            Route::get('audit/export', [AuditController::class, 'export'])
                ->middleware('throttle:5,1')
                ->name('audit.export');
            Route::patch('audit/alerts/{alert}/resolve', [AuditController::class, 'resolve'])
                ->name('audit.alerts.resolve');
            Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::patch('documents/{document}/review', [DocumentController::class, 'review'])
                ->name('documents.review');
            Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])
                ->name('users.status.update');
            Route::patch('users/{user}/role', [UserController::class, 'updateRole'])
                ->name('users.role.update');
            Route::patch('users/{user}/platform-role', [UserController::class, 'updatePlatformRole'])
                ->name('users.platform-role.update');
            Route::patch('users/{user}/storage-quota', [UserController::class, 'updateStorageQuota'])
                ->name('users.storage-quota.update');
            Route::patch('companies/{company}/status', [CompanyController::class, 'updateStatus'])
                ->name('companies.status.update');
            Route::patch('billing/plans/{plan}', [BillingController::class, 'updatePlan'])
                ->name('billing.plans.update');
            Route::patch(
                'billing/manual-reviews/{billingChangeIntent:public_id}',
                [BillingController::class, 'resolveManualReview'],
            )->name('billing.manual-reviews.resolve');
            Route::patch('referrals/{referral}', [ReferralController::class, 'update'])
                ->name('referrals.update');
            Route::patch('moderation/feedback/{feedback}', [ModerationController::class, 'reviewFeedback'])
                ->name('moderation.feedback.review');
            Route::patch('moderation/cases/{case}', [ModerationController::class, 'updateCase'])
                ->name('moderation.cases.update');
            Route::patch('settings/theme', [SettingController::class, 'updateTheme'])
                ->name('settings.theme.update');
            Route::patch('settings/platform', [SettingController::class, 'update'])
                ->name('settings.platform.update');
            Route::post('settings/skills', [SkillTaxonomyController::class, 'store'])
                ->name('settings.skills.store');
            Route::patch('settings/skills/{skill}', [SkillTaxonomyController::class, 'update'])
                ->name('settings.skills.update');
            Route::delete('settings/skills/{skill}', [SkillTaxonomyController::class, 'destroy'])
                ->name('settings.skills.destroy');
            Route::post('settings/platform-roles', [PlatformRoleController::class, 'store'])
                ->name('settings.platform-roles.store');
            Route::patch('settings/platform-roles/{platformRole}', [PlatformRoleController::class, 'update'])
                ->name('settings.platform-roles.update');
            Route::delete('settings/platform-roles/{platformRole}', [PlatformRoleController::class, 'destroy'])
                ->name('settings.platform-roles.destroy');
            Route::post('settings/ads/{campaign}/media', [SettingController::class, 'uploadAdMedia'])
                ->name('settings.ads.media.store');
            Route::delete('settings/ads/{campaign}/media', [SettingController::class, 'deleteAdMedia'])
                ->name('settings.ads.media.destroy');
            Route::post('system/feature-flags', [FeatureFlagController::class, 'store'])
                ->name('feature-flags.store');
            Route::patch('system/feature-flags/{featureFlag}', [FeatureFlagController::class, 'update'])
                ->name('feature-flags.update');
            Route::delete('system/feature-flags/{featureFlag}', [FeatureFlagController::class, 'destroy'])
                ->name('feature-flags.destroy');
            Route::post('system/gdpr-requests', [GdprRequestController::class, 'store'])
                ->name('gdpr-requests.store');
            Route::patch('system/gdpr-requests/{gdprRequest}', [GdprRequestController::class, 'update'])
                ->name('gdpr-requests.update');
            Route::get('system/gdpr-requests/{gdprRequest}/download', [GdprRequestController::class, 'download'])
                ->middleware(['signed', 'throttle:10,1'])
                ->name('gdpr-requests.download');
            Route::post('system/access-list', [AccessListEntryController::class, 'store'])
                ->name('access-list.store');
            Route::patch('system/access-list/{accessListEntry}', [AccessListEntryController::class, 'update'])
                ->name('access-list.update');
            Route::delete('system/access-list/{accessListEntry}', [AccessListEntryController::class, 'destroy'])
                ->name('access-list.destroy');
            Route::post('system/email-templates', [EmailTemplateController::class, 'upsert'])
                ->name('email-templates.upsert');
            Route::delete('system/email-templates/{key}', [EmailTemplateController::class, 'destroy'])
                ->where('key', '[a-z0-9._-]+')
                ->name('email-templates.destroy');
        });
    });

Route::post('support/impersonation/stop', [ImpersonationController::class, 'stop'])
    ->middleware(['auth', 'verified'])
    ->withoutMiddleware(BlockSupportWrites::class)
    ->name('support.impersonation.stop');
