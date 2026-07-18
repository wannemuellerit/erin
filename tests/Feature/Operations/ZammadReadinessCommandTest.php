<?php

use App\Services\Ticketing\ZammadWebhookSignature;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function configureZammadReadiness(): void
{
    config()->set('app.url', 'https://staging.erin.wannemueller.dev');
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://support.wannemueller.dev');
    config()->set('services.zammad.token', 'never-print-this-zammad-token');
    config()->set('services.zammad.group', 'Erin Support');
    config()->set('services.zammad.webhook_secret', str_repeat('w', 40));
    config()->set('services.zammad.timeout', 2);
}

it('performs a read-only Zammad readiness check without exposing credentials', function () {
    configureZammadReadiness();
    Http::fake([
        'https://support.wannemueller.dev/api/v1/users/me' => Http::response([
            'id' => 42,
            'active' => true,
            'login' => 'erin-integration',
        ]),
    ]);

    $exitCode = Artisan::call('erin:zammad:smoke');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Token authentifiziert einen aktiven Zammad-Benutzer')
        ->toContain('ausschließlich GET /api/v1/users/me')
        ->not->toContain('never-print-this-zammad-token')
        ->not->toContain(str_repeat('w', 40));

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && $request->url() === 'https://support.wannemueller.dev/api/v1/users/me'
        && $request->hasHeader('Authorization', 'Token token=never-print-this-zammad-token'));
});

it('fails locally without sending a request when the Zammad configuration is unsafe', function () {
    config()->set('app.url', 'http://localhost:8000');
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'http://127.0.0.1:3000?token=unsafe');
    config()->set('services.zammad.token', 'sensitive-token');
    config()->set('services.zammad.group', '');
    config()->set('services.zammad.webhook_secret', 'short-secret');
    Http::fake();

    $exitCode = Artisan::call('erin:zammad:smoke');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Zammad ist noch nicht staging-bereit')
        ->toContain('ZAMMAD_URL muss eine HTTPS-URL')
        ->toContain('ZAMMAD_WEBHOOK_SECRET muss mindestens 32')
        ->not->toContain('sensitive-token')
        ->not->toContain('short-secret');

    Http::assertNothingSent();
});

it('reports rejected Zammad authentication without leaking the remote response', function () {
    configureZammadReadiness();
    Http::fake([
        'https://support.wannemueller.dev/api/v1/users/me' => Http::response([
            'error' => 'remote-sensitive-diagnostic',
        ], 401),
    ]);

    $exitCode = Artisan::call('erin:zammad:smoke');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('API-Token oder dessen Berechtigungen abgelehnt')
        ->not->toContain('remote-sensitive-diagnostic')
        ->not->toContain('never-print-this-zammad-token');
});

it('does not forward the Zammad token across redirects', function () {
    configureZammadReadiness();
    Http::fake([
        'https://support.wannemueller.dev/api/v1/users/me' => Http::response(
            '',
            302,
            ['Location' => 'https://redirect-target.example/api/v1/users/me'],
        ),
        '*' => Http::response(['id' => 99]),
    ]);

    $exitCode = Artisan::call('erin:zammad:smoke');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Weiterleitungen werden zum Schutz des Tokens nicht verfolgt')
        ->not->toContain('redirect-target.example')
        ->not->toContain('never-print-this-zammad-token');

    Http::assertSentCount(1);
});

it('uses the same strict signature implementation for smoke checks and incoming webhooks', function () {
    $signatures = app(ZammadWebhookSignature::class);
    $payload = '{"ticket":{"id":42}}';
    $secret = str_repeat('s', 40);
    $signature = $signatures->create($payload, $secret);

    expect($signatures->isValid($payload, $signature, $secret))->toBeTrue()
        ->and($signatures->isValid($payload.' ', $signature, $secret))->toBeFalse()
        ->and($signatures->isValid($payload, mb_strtoupper($signature), $secret))->toBeFalse()
        ->and($signatures->isValid($payload, 'sha1=invalid', $secret))->toBeFalse();
});
