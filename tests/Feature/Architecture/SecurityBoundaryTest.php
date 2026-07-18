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
