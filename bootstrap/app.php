<?php

use App\Http\Middleware\AttachCorrelationId;
use App\Http\Middleware\BlockSupportWrites;
use App\Http\Middleware\EnsureCapability;
use App\Http\Middleware\EnsureCompanyMember;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Http\Middleware\EnsurePasswordChangeCompleted;
use App\Http\Middleware\EnsurePlatformAccess;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Http\Middleware\EnsureSubscribedCompany;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordUserActivity;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackLastActivity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Env;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth', 'staff.2fa']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = Env::get('TRUSTED_PROXIES');
        if (is_string($trustedProxies) && $trustedProxies !== '') {
            $middleware->trustProxies(at: array_values(array_filter(array_map(
                'trim',
                explode(',', $trustedProxies),
            ))));
        }

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->validateCsrfTokens(except: [
            'billing/webhook',
            'integrations/zammad/webhook',
        ]);

        $middleware->web(append: [
            AttachCorrelationId::class,
            HandleAppearance::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            EnsurePlatformAccess::class,
            EnsurePasswordChangeCompleted::class,
            BlockSupportWrites::class,
            TrackLastActivity::class,
            RecordUserActivity::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'company.member' => EnsureCompanyMember::class,
            'capability' => EnsureCapability::class,
            'company.subscribed' => EnsureSubscribedCompany::class,
            'onboarding.complete' => EnsureOnboardingComplete::class,
            'staff.2fa' => EnsureStaffTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
