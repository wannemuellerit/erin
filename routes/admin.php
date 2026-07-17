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
use App\Http\Controllers\Admin\ReferralController;
use App\Http\Controllers\Admin\SettingController;
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
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::patch('documents/{document}/review', [DocumentController::class, 'review'])
            ->name('documents.review');
        Route::get('visa', [VisaController::class, 'index'])->name('visa.index');
        Route::get('support', [SupportController::class, 'index'])->name('support.index');
        Route::patch('support/{ticket}', [SupportController::class, 'update'])
            ->name('support.update');
        Route::post('support/{ticket}/replies', [SupportController::class, 'reply'])
            ->name('support.reply');
        Route::post('support/impersonate/{user}', [ImpersonationController::class, 'start'])
            ->name('support.impersonation.start');
        Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
        Route::get('referrals', [ReferralController::class, 'index'])->name('referrals.index');
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::get('system', [SystemController::class, 'index'])->name('system.index');

        Route::middleware('role:super_admin')->group(function (): void {
            Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])
                ->name('users.status.update');
            Route::patch('users/{user}/role', [UserController::class, 'updateRole'])
                ->name('users.role.update');
            Route::patch('companies/{company}/status', [CompanyController::class, 'updateStatus'])
                ->name('companies.status.update');
            Route::patch('billing/plans/{plan}', [BillingController::class, 'updatePlan'])
                ->name('billing.plans.update');
            Route::patch('referrals/{referral}', [ReferralController::class, 'update'])
                ->name('referrals.update');
            Route::patch('settings/theme', [SettingController::class, 'updateTheme'])
                ->name('settings.theme.update');
            Route::patch('settings/platform', [SettingController::class, 'update'])
                ->name('settings.platform.update');
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
