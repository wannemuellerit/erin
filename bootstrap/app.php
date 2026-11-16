<?php

use App\Http\Middleware\AttachCorrelationId;
use App\Http\Middleware\BlockSupportWrites;
use App\Http\Middleware\EnforceMaintenanceMode;
use App\Http\Middleware\EnsureCapability;
use App\Http\Middleware\EnsureCompanyMember;
use App\Http\Middleware\EnsureFeatureEnabled;
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
use Inertia\Inertia;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
            'integrations/calendar/*/webhook',
            'integrations/mail/*/webhook',
            'integrations/external-notifications/*/webhook',
            'integrations/partners/*/webhook',
            'integrations/payouts/*/webhook',
            'integrations/livekit/webhook',
        ]);

        $middleware->web(append: [
            AttachCorrelationId::class,
            HandleAppearance::class,
            SetLocale::class,
            EnforceMaintenanceMode::class,
            HandleInertiaRequests::class,
            EnsurePlatformAccess::class,
            EnsurePasswordChangeCompleted::class,
            BlockSupportWrites::class,
            TrackLastActivity::class,
            RecordUserActivity::class,
            AddLinkHeadersForPreloadedAssets::using(6),
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'company.member' => EnsureCompanyMember::class,
            'feature' => EnsureFeatureEnabled::class,
            'capability' => EnsureCapability::class,
            'company.subscribed' => EnsureSubscribedCompany::class,
            'onboarding.complete' => EnsureOnboardingComplete::class,
            'staff.2fa' => EnsureStaffTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ApiErrorException $exception, Request $request) {
            if (! $request->routeIs('employer.billing.*')) {
                return null;
            }
            $message = __('Stripe ist momentan nicht erreichbar oder nicht korrekt eingerichtet. Bitte prüfe den Abrechnungsstatus vor einem erneuten Versuch.');

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 503);
            }

            return back()->with('error', $message);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (
            HttpResponse $response,
            Throwable $exception,
            Request $request,
        ): HttpResponse {
            $status = $response->getStatusCode();

            // Expected business-rule rejections must remain visible inside the
            // portal instead of opening an unstyled Inertia error modal.
            if ($status === 422 && $exception instanceof HttpExceptionInterface
                && $request->header('X-Inertia') === 'true'
                && ! $request->isMethod('GET') && ! $request->expectsJson()) {
                return back()->withErrors([
                    'action' => $exception->getMessage() ?: __('Diese Aktion ist im aktuellen Status nicht möglich.'),
                ]);
            }

            if (
                ! in_array($status, [
                    HttpResponse::HTTP_FORBIDDEN,
                    HttpResponse::HTTP_NOT_FOUND,
                ], true)
                || $request->is('api/*')
                || $request->expectsJson()
            ) {
                return $response;
            }

            if ($request->header('X-Inertia') === 'true') {
                $inertiaResponse = Inertia::render('errors/NotFound', [
                    'status' => $status,
                ])->toResponse($request);
                $inertiaResponse->setStatusCode($status);
                $inertiaResponse->headers->set('Cache-Control', 'no-store, private');

                return $inertiaResponse;
            }

            // Direct requests must stay renderable even when the JavaScript
            // bundle is unavailable or Laravel fails before Inertia starts.
            return response()
                ->view('errors.404', status: $status)
                ->header('Cache-Control', 'no-store, private');
        });
    })->create();
