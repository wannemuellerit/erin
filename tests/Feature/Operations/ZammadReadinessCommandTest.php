<?php

use App\Services\Ticketing\ZammadWebhookSignature;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function configureZammadReadiness(): void
{
    config()->set('app.url', 'https://staging.erin.wannemueller.dev');
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'https://support.wannemueller.dev');
    config()->set(
        'services.zammad.webhook_callback_url',
        'https://staging.erin.wannemueller.dev/integrations/zammad/webhook',
    );
    config()->set('services.zammad.allow_local_http', false);
    config()->set('services.zammad.local_http_hosts', []);
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
    config()->set(
        'services.zammad.webhook_callback_url',
        'http://localhost:8000/integrations/zammad/webhook',
    );
    config()->set('services.zammad.token', 'sensitive-token');
    config()->set('services.zammad.group', '');
    config()->set('services.zammad.webhook_secret', 'short-secret');
    Http::fake();

    $exitCode = Artisan::call('erin:zammad:smoke');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Zammad ist noch nicht staging-bereit')
        ->toContain('ZAMMAD_URL muss HTTPS verwenden')
        ->toContain('ZAMMAD_WEBHOOK_SECRET muss mindestens 32')
        ->not->toContain('sensitive-token')
        ->not->toContain('short-secret');

    Http::assertNothingSent();
});

it('accepts only explicitly allowlisted Docker hosts in local environments', function () {
    config()->set('app.env', 'local');
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'http://zammad:8080');
    config()->set('services.zammad.webhook_callback_url', 'http://laravel:8000/integrations/zammad/webhook');
    config()->set('services.zammad.allow_local_http', true);
    config()->set('services.zammad.local_http_hosts', ['zammad', 'laravel']);
    config()->set('services.zammad.token', 'local-zammad-token');
    config()->set('services.zammad.group', 'Erin Support');
    config()->set('services.zammad.webhook_secret', str_repeat('l', 40));
    Http::fake([
        'http://zammad:8080/api/v1/users/me' => Http::response([
            'id' => 43,
            'active' => true,
        ]),
    ]);

    $exitCode = Artisan::call('erin:zammad:smoke');

    expect($exitCode)->toBe(0);
    Http::assertSentCount(1);

    config()->set('services.zammad.url', 'http://postgres:5432');
    Http::fake();

    $exitCode = Artisan::call('erin:zammad:smoke');

    expect($exitCode)->toBe(1);
    Http::assertNothingSent();
});

it('rejects local HTTP endpoints in production even when the flag and hosts are configured', function () {
    config()->set('app.env', 'production');
    config()->set('services.zammad.enabled', true);
    config()->set('services.zammad.url', 'http://zammad:8080');
    config()->set('services.zammad.webhook_callback_url', 'http://laravel:8000/integrations/zammad/webhook');
    config()->set('services.zammad.allow_local_http', true);
    config()->set('services.zammad.local_http_hosts', ['zammad', 'laravel']);
    config()->set('services.zammad.token', 'must-remain-private');
    config()->set('services.zammad.group', 'Erin Support');
    config()->set('services.zammad.webhook_secret', str_repeat('p', 40));
    Http::fake();

    $exitCode = Artisan::call('erin:zammad:smoke');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('ZAMMAD_URL muss HTTPS verwenden')
        ->not->toContain('must-remain-private');
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

it('rejects inactive and malformed authenticated users', function (array $response) {
    configureZammadReadiness();
    Http::fake([
        'https://support.wannemueller.dev/api/v1/users/me' => Http::response($response),
    ]);

    $exitCode = Artisan::call('erin:zammad:smoke');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('keinen aktiven Zammad-Benutzer')
        ->not->toContain('never-print-this-zammad-token');
})->with([
    'inactive user' => [['id' => 42, 'active' => false]],
    'missing user ID' => [['active' => true]],
    'empty API response' => [[]],
]);

it('reports provider outages without leaking connection diagnostics', function () {
    configureZammadReadiness();
    Http::fake([
        'https://support.wannemueller.dev/api/v1/users/me' => Http::failedConnection(
            'tcp://internal-host:443 token=remote-secret',
        ),
    ]);

    $exitCode = Artisan::call('erin:zammad:smoke');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('nicht erreichbar')
        ->not->toContain('internal-host')
        ->not->toContain('remote-secret')
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

it('keeps the pinned Zammad token schema and finalizes rotation only after smoke testing', function () {
    $bootstrap = file_get_contents(base_path('scripts/zammad/bootstrap.rb'));
    $shellBootstrap = file_get_contents(base_path('scripts/zammad/bootstrap.sh'));
    $smokePosition = strpos((string) $shellBootstrap, 'php artisan erin:zammad:smoke');
    $finalizePosition = strpos((string) $shellBootstrap, 'ERIN_ZAMMAD_BOOTSTRAP_ACTION=finalize');

    expect($bootstrap)->toBeString()
        ->toContain(
            'name: "Erin local integration',
            "action == 'finalize'",
            '.where.not(id: keep_token.id)',
        )
        ->not->toContain('label: "Erin local integration')
        ->and($shellBootstrap)->toBeString()
        ->toContain('dotenv_quote "${group_name}"')
        ->toContain(
            'Der Zammad-Gruppenname darf keinen Zeilenumbruch enthalten.',
            'php artisan erin:zammad:smoke',
            'ERIN_ZAMMAD_BOOTSTRAP_ACTION=finalize',
            'bisherige Erin-Tokens bleiben als Rückfall erhalten',
        )
        ->and($smokePosition)->not->toBeFalse()
        ->and($finalizePosition)->not->toBeFalse()
        ->and((int) $smokePosition)->toBeLessThan((int) $finalizePosition);
});

it('ships a local E2E script with an explicit non-production write guard', function () {
    $phpScript = file_get_contents(base_path('scripts/zammad/e2e.php'));
    $shellScript = file_get_contents(base_path('scripts/zammad/e2e.sh'));

    expect($phpScript)->toBeString()
        ->toContain("\$app->environment(['local', 'testing'])")
        ->toContain('SupportAttachmentIntegrityVerifier')
        ->and($shellScript)->toBeString()
        ->toContain('scripts/zammad/e2e.php prepare')
        ->toContain('scripts/zammad/e2e.php verify')
        ->toContain('unset token reply_data reply_payload');
});

it('forwards reconciliation and orphan-protection settings to production app services', function () {
    $compose = file_get_contents(base_path('compose.production.yaml'));
    $productionEnv = file_get_contents(base_path(
        'docker/production/env.example',
    ));

    expect($compose)->toBeString()
        ->toContain(
            'ZAMMAD_RECONCILE_INITIAL_DELAY_SECONDS: ${ZAMMAD_RECONCILE_INITIAL_DELAY_SECONDS:-30}',
            'ZAMMAD_RECONCILE_INTERVAL_SECONDS: ${ZAMMAD_RECONCILE_INTERVAL_SECONDS:-15}',
            'ZAMMAD_RECONCILE_REQUIRED_MISSES: ${ZAMMAD_RECONCILE_REQUIRED_MISSES:-3}',
            'ZAMMAD_UNMATCHED_WEBHOOK_RETENTION_HOURS: ${ZAMMAD_UNMATCHED_WEBHOOK_RETENTION_HOURS:-24}',
            'SUPPORT_ATTACHMENT_ORPHAN_GRACE_HOURS: ${SUPPORT_ATTACHMENT_ORPHAN_GRACE_HOURS:-24}',
        )
        ->and($productionEnv)->toBeString()
        ->toContain('ZAMMAD_UNMATCHED_WEBHOOK_RETENTION_HOURS=24');
});
