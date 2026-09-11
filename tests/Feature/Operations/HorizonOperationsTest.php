<?php

use App\Enums\UserRole;
use App\Http\Middleware\RedactHorizonPayloads;
use App\Jobs\ImportZammadAttachment;
use App\Jobs\ProcessCandidateImport;
use App\Jobs\ProcessReferralPayout;
use App\Jobs\ProcessSupportWebhookOutbox;
use App\Jobs\ProcessSupportZammadWebhookInbox;
use App\Jobs\ScanCandidateDocument;
use App\Jobs\ScanCandidateProfilePhoto;
use App\Jobs\ScanCompanyMedia;
use App\Jobs\ScanJobMedia;
use App\Jobs\ScanMessageAttachment;
use App\Jobs\ScanSupportTicketAttachment;
use App\Jobs\SendExternalNotification;
use App\Jobs\SyncSupportMessageToProvider;
use App\Jobs\SyncSupportTicketToProvider;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Notifications\EmailTemplatePreviewNotification;
use Composer\InstalledVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use NotificationChannels\WebPush\WebPushChannel;

uses(RefreshDatabase::class);

it('runs a Laravel 13 compatible Horizon release with isolated supervisors', function () {
    expect(version_compare(
        (string) InstalledVersions::getVersion('laravel/horizon'),
        '5.48.1.0',
        '>=',
    ))->toBeTrue()
        ->and(class_exists(Horizon::class))->toBeTrue()
        ->and(config('horizon.fast_termination'))->toBeTrue()
        ->and(config('horizon.middleware'))->toContain(
            'auth',
            'verified',
            'role:super_admin',
            'staff.2fa',
            RedactHorizonPayloads::class,
        )
        ->and(array_keys(config('horizon.defaults')))->toEqualCanonicalizing([
            'supervisor-default',
            'supervisor-notifications',
            'supervisor-scans',
            'supervisor-imports',
            'supervisor-webhooks',
            'supervisor-ai',
            'supervisor-payments',
        ])
        ->and(config('operations.queue.queues'))->toContain(
            'payments',
            'webhooks',
            'notifications',
            'scans',
            'imports',
            'ai',
            'default',
        );
});

it('allows the Horizon dashboard only to a two-factor protected superadmin', function () {
    $candidate = User::factory()->create();
    $support = User::factory()->withTwoFactor()->create(['role' => UserRole::Support]);
    $unprotectedAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $protectedAdmin = User::factory()->withTwoFactor()->create(['role' => UserRole::SuperAdmin]);

    expect(Gate::forUser($candidate)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($support)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($unprotectedAdmin)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($protectedAdmin)->allows('viewHorizon'))->toBeTrue();

    $this->get('/horizon')->assertRedirect(route('login'));
    $this->actingAs($candidate)->get('/horizon')->assertForbidden();
    $this->actingAs($support)->get('/horizon')->assertForbidden();
    $this->actingAs($unprotectedAdmin)->get('/horizon')->assertForbidden();
    $this->actingAs($protectedAdmin)->get('/horizon')->assertOk();
});

it('redacts serialized commands and exception details from Horizon JSON', function () {
    $middleware = new RedactHorizonPayloads;
    $response = $middleware->handle(
        Request::create('/horizon/api/jobs/recent'),
        fn (): JsonResponse => response()->json([
            'jobs' => [[
                'payload' => json_encode([
                    'displayName' => 'App\\Jobs\\SecretJob',
                    'data' => [
                        'commandName' => 'App\\Jobs\\SecretJob',
                        'command' => 'serialized-secret-token',
                    ],
                ], JSON_THROW_ON_ERROR),
                'exception' => 'password=must-not-leak',
            ]],
        ]),
    );
    $data = $response->getData(true);
    $payload = json_decode((string) $data['jobs'][0]['payload'], true, flags: JSON_THROW_ON_ERROR);
    $content = (string) $response->getContent();

    expect($payload['data'])->toBe([
        'commandName' => 'App\\Jobs\\SecretJob',
        'redacted' => true,
    ])->and($data['jobs'][0]['exception'])->toBe('[exception details redacted]')
        ->and($content)->toContain('redacted')
        ->not->toContain('serialized-secret-token')
        ->not->toContain('must-not-leak');
});

it('routes workload classes onto their governed Horizon queues', function () {
    $queues = [
        (new ScanCandidateDocument(1))->queue => 'scans',
        (new ScanCandidateProfilePhoto(1, 'quarantine/photo.jpg'))->queue => 'scans',
        (new ScanCompanyMedia(1))->queue => 'scans',
        (new ScanJobMedia(1))->queue => 'scans',
        (new ScanMessageAttachment(1))->queue => 'scans',
        (new ScanSupportTicketAttachment(1))->queue => 'scans',
        (new ProcessCandidateImport(1))->queue => 'imports',
        (new SendExternalNotification(1))->queue => 'notifications',
        (new SyncSupportMessageToProvider(1))->queue => 'webhooks',
        (new SyncSupportTicketToProvider(1))->queue => 'webhooks',
        (new ImportZammadAttachment(1))->queue => 'webhooks',
        (new ProcessSupportWebhookOutbox(1))->queue => 'webhooks',
        (new ProcessSupportZammadWebhookInbox(1))->queue => 'webhooks',
        (new ProcessReferralPayout(1))->queue => 'payments',
    ];

    expect(array_keys($queues))->toEqualCanonicalizing([
        'scans',
        'imports',
        'notifications',
        'webhooks',
        'payments',
    ]);

    $activityQueues = (new ActivityNotification([]))->viaQueues();
    expect($activityQueues)->toMatchArray([
        'mail' => 'notifications',
        'database' => 'notifications',
        'broadcast' => 'notifications',
        WebPushChannel::class => 'notifications',
    ])->and((new EmailTemplatePreviewNotification('system', 'de'))->viaQueues())
        ->toBe(['mail' => 'notifications']);
});

it('records Horizon queue metrics every five minutes', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('horizon:snapshot')
        ->toContain('erin:ops:queue-health --json');
});

it('keeps Horizon and the fallback worker mutually exclusive in both stacks', function () {
    foreach (['compose.yaml', 'compose.production.yaml'] as $composePath) {
        $compose = (string) file_get_contents(base_path($composePath));

        expect($compose)
            ->toContain("\n  horizon:")
            ->toContain('["php", "artisan", "horizon"]')
            ->toContain("\n  queue:")
            ->toContain('profiles: ["queue-fallback"]')
            ->toContain('--queue=payments,webhooks,notifications,scans,imports,ai,default');
    }

    $runbook = (string) file_get_contents(base_path('docs/operations/horizon.md'));
    expect($runbook)
        ->toContain('must never run at the same time as Horizon')
        ->toContain('Do not overlap consumers during this cutover')
        ->toContain('Never purge Redis queues during migration or rollback')
        ->toContain('docker compose --profile queue-fallback up -d queue');
});
