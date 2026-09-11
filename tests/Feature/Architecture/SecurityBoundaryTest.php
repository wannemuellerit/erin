<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

it('keeps demo identities out of frontend authorization decisions', function () {
    $violations = collect(File::allFiles(resource_path('js')))
        ->filter(static fn (SplFileInfo $file): bool => in_array(
            $file->getExtension(),
            ['ts', 'vue'],
            true,
        ))
        ->filter(static fn (SplFileInfo $file): bool => str_contains(
            (string) file_get_contents($file->getPathname()),
            '@wannemueller.dev',
        ))
        ->map(static fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

it('does not read environment variables directly from application runtime code', function () {
    $violations = collect([
        ...File::allFiles(app_path()),
        ...File::allFiles(base_path('routes')),
    ])
        ->filter(static fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->filter(static fn (SplFileInfo $file): bool => preg_match(
            '/\benv\s*\(/',
            (string) file_get_contents($file->getPathname()),
        ) === 1)
        ->map(static fn (SplFileInfo $file): string => $file->getPathname())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

it('keeps debugging calls out of shipped application code', function () {
    $violations = collect([
        ...File::allFiles(app_path()),
        ...File::allFiles(base_path('routes')),
        ...File::allFiles(resource_path('js')),
    ])
        ->filter(static fn (SplFileInfo $file): bool => in_array(
            $file->getExtension(),
            ['php', 'ts', 'vue'],
            true,
        ))
        ->filter(static fn (SplFileInfo $file): bool => preg_match(
            '/\b(?:dd|dump|ray)\s*\(/',
            (string) file_get_contents($file->getPathname()),
        ) === 1)
        ->map(static fn (SplFileInfo $file): string => $file->getPathname())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

it('protects every role namespace with its complete middleware boundary', function () {
    $requirements = [
        'admin.' => ['auth', 'verified', 'role:super_admin,support', 'staff.2fa'],
        'employer.' => [
            'auth',
            'verified',
            'role:company',
            'company.member',
            'onboarding.complete',
        ],
        'candidate.' => [
            'auth',
            'verified',
            'role:candidate',
            'onboarding.complete',
        ],
    ];

    foreach ($requirements as $namePrefix => $requiredMiddleware) {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (Illuminate\Routing\Route $route): bool => str_starts_with(
                (string) $route->getName(),
                $namePrefix,
            ));

        expect($routes)->not->toBeEmpty();

        $routes->each(function (
            Illuminate\Routing\Route $route,
        ) use ($requiredMiddleware): void {
            foreach ($requiredMiddleware as $middleware) {
                expect($route->gatherMiddleware())->toContain($middleware);
            }
        });
    }
});

it('enforces staff two factor on shared authenticated routes', function () {
    foreach ([
        'dashboard',
        'support.index',
        'support.attachments.download',
    ] as $routeName) {
        $route = Route::getRoutes()->getByName($routeName);

        expect($route)->not->toBeNull()
            ->and($route?->gatherMiddleware())
            ->toContain('auth', 'verified', 'staff.2fa');
    }
});

it('keeps support downloads authenticated signed and rate limited', function () {
    $route = Route::getRoutes()->getByName('support.attachments.download');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())
        ->toContain('auth', 'verified', 'signed', 'throttle:60,1');
});

it('documents and forwards every optional product integration boundary', function () {
    $integrationKeys = [
        'MAIL_DELIVERY_PROVIDER',
        'MAIL_DELIVERY_WEBHOOK_SECRET',
        'MAIL_DELIVERY_WEBHOOK_MAX_BYTES',
        'TWILIO_ENABLED',
        'TWILIO_ACCOUNT_SID',
        'TWILIO_AUTH_TOKEN',
        'TWILIO_SMS_FROM',
        'TWILIO_WHATSAPP_FROM',
        'TWILIO_WEBHOOK_SECRET',
        'TWILIO_WEBHOOK_MAX_BYTES',
        'TWILIO_ALLOWED_COUNTRIES',
        'TWILIO_MONTHLY_COST_LIMIT_MICROS',
        'TWILIO_USER_HOURLY_LIMIT',
        'PARTNER_WEBHOOK_SECRET',
        'PARTNER_WEBHOOK_MAX_BYTES',
        'PAYOUT_PROVIDER',
        'PAYOUT_WEBHOOK_SECRET',
        'PAYOUT_WEBHOOK_MAX_BYTES',
        'PAYOUT_FRAUD_REVIEW_SCORE',
        'PAYOUT_MANUAL_REVIEW_AMOUNT_CENTS',
        'SUPPORT_CHATBOT_ENABLED',
        'SUPPORT_CHATBOT_PROVIDER_ENABLED',
        'SUPPORT_CHATBOT_RETENTION_DAYS',
        'SUPPORT_CHATBOT_MAX_SOURCES',
        'SUPPORT_CHATBOT_DAILY_PROVIDER_BUDGET',
        'SUPPORT_CHATBOT_CIRCUIT_BREAKER_FAILURES',
        'SUPPORT_CHATBOT_CIRCUIT_BREAKER_MINUTES',
        'LIVEKIT_WEBHOOK_MAX_BYTES',
    ];
    $localExample = (string) file_get_contents(base_path('.env.example'));
    $productionExample = (string) file_get_contents(base_path('docker/production/env.example'));
    $productionCompose = (string) file_get_contents(base_path('compose.production.yaml'));

    foreach ($integrationKeys as $key) {
        expect($localExample)->toMatch('/^'.preg_quote($key, '/').'=/m')
            ->and($productionExample)->toMatch('/^'.preg_quote($key, '/').'=/m')
            ->and($productionCompose)->toContain($key.':');
    }
});
