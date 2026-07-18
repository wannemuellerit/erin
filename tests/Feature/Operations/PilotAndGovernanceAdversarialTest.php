<?php

use App\Services\Operations\GovernanceAdversarialPreflight;
use App\Services\Operations\SyntheticPilotDrill;
use Illuminate\Support\Facades\Artisan;

it('detects every synthetic governance attack without representing an external approval', function () {
    $result = app(GovernanceAdversarialPreflight::class)->run();

    expect($result)
        ->status->toBe('passed')
        ->classification->toBe('SYNTHETIC_ADVERSARIAL_PREFLIGHT')
        ->and($result['summary'])->toMatchArray([
            'attacks' => 13,
            'detected' => 13,
            'escaped' => 0,
        ])
        ->and($result['baseline'])->toBe([
            'status' => 'passed',
            'errors' => [],
        ])
        ->and(collect($result['attacks'])->pluck('status')->unique()->all())
        ->toBe(['detected'])
        ->and(collect($result['attacks'])->every(
            fn (array $attack): bool => collect($attack['expected_errors'])
                ->every(fn (string $error): bool => in_array($error, $attack['error_delta'], true)),
        ))->toBeTrue();
})->group('ops');

it('emits machine-readable governance preflight results without approval credentials', function () {
    $exitCode = Artisan::call('erin:ops:governance-adversarial', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(
            '"status": "passed"',
            '"escaped": 0',
            '"classification": "SYNTHETIC_ADVERSARIAL_PREFLIGHT"',
        )
        ->not->toContain('APP_KEY')
        ->not->toContain('PASSWORD');
})->group('ops');

it('passes the bounded synthetic pilot baseline but leaves formal gates open', function () {
    $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
    $result = app(SyntheticPilotDrill::class)->run('pass', $timestamp, $timestamp);

    expect($result['result'])->toMatchArray([
        'status' => 'passed',
        'stop_triggered' => false,
        'failed_checks' => [],
    ])
        ->and($result['production_gate_eligible'])->toBeFalse()
        ->and($result['synthetic'])->toBeTrue()
        ->and($result['evidence_type'])->toBe('synthetic_local_pilot_control_model')
        ->and($result['execution_mode'])->toBe('rule_model_only')
        ->and(collect($result['external_gates'])->unique()->values()->all())->toBe(['open']);
})->group('ops');

it('triggers immediate stops for safety violations rather than reporting a soft failure', function (string $scenario, string $check) {
    $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
    $result = app(SyntheticPilotDrill::class)->run($scenario, $timestamp, $timestamp);

    expect($result['result']['status'])->toBe('stopped')
        ->and($result['result']['stop_triggered'])->toBeTrue()
        ->and($result['result']['failed_checks'])->toContain($check);
})->with([
    ['tenant-leak', 'tenant_data_leak.none'],
    ['document-leak', 'unauthorized_document_download.none'],
    ['ai-autonomy', 'ai_automatic_status_change.none'],
    ['webhook-duplication', 'webhook.idempotency'],
    ['monitoring-gap', 'monitoring.modeled_available'],
    ['audit-log-gap', 'audit_log.modeled_available'],
    ['critical-incident', 'incidents.none_high_or_critical'],
])->group('ops');

it('fails non-stop pilot acceptance breaches without pretending a safety stop occurred', function (string $scenario, string $check) {
    $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
    $result = app(SyntheticPilotDrill::class)->run($scenario, $timestamp, $timestamp);

    expect($result['result']['status'])->toBe('failed')
        ->and($result['result']['stop_triggered'])->toBeFalse()
        ->and($result['result']['failed_checks'])->toContain($check);
})->with([
    ['support-desync', 'support.consistency'],
    ['role-collision', 'roles.separated'],
    ['participant-overflow', 'participants.within_limits'],
    ['company-overflow', 'participants.within_limits'],
    ['rollback-failure', 'rollback.modeled_success'],
    ['maintenance-mode-failure', 'maintenance_mode.modeled_success'],
    ['external-notification-leak', 'external_notifications.modeled_disabled'],
    ['interview-authorization-gap', 'interview.authorization'],
    ['timeline-inconsistency', 'timelines.consistent'],
    ['protected-match-factor', 'matching.no_protected_factors'],
])->group('ops');

it('requires explicit local confirmation and refuses synthetic drills in production', function () {
    $exitCode = Artisan::call('erin:ops:pilot-drill', [
        '--scenario' => 'pass',
        '--json' => true,
    ]);
    expect($exitCode)->toBe(2)
        ->and(Artisan::output())->toContain('Bestätigung fehlt');

    config(['app.env' => 'production']);
    $exitCode = Artisan::call('erin:ops:pilot-drill', [
        '--scenario' => 'pass',
        '--confirm' => 'SYNTHETIC_LOCAL_ONLY',
        '--json' => true,
    ]);
    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('nicht in der Produktion');
})->group('ops');

it('rejects unknown scenarios and path traversal before writing evidence', function () {
    $exitCode = Artisan::call('erin:ops:pilot-drill', [
        '--scenario' => 'make-everything-green',
        '--confirm' => 'SYNTHETIC_LOCAL_ONLY',
        '--json' => true,
    ]);
    expect($exitCode)->toBe(2)
        ->and(Artisan::output())->toContain('Unbekanntes Szenario');

    $exitCode = Artisan::call('erin:ops:pilot-drill', [
        '--scenario' => 'pass',
        '--confirm' => 'SYNTHETIC_LOCAL_ONLY',
        '--output' => '../../outside.json',
        '--json' => true,
    ]);
    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Unsicherer Evidenzpfad');
})->group('ops');

it('writes non-overwriting synthetic pilot evidence with a checksum sidecar', function () {
    config(['app.env' => 'testing']);
    $relative = 'pilot/testing-'.bin2hex(random_bytes(5)).'.json';
    $path = storage_path('app/operations/evidence/'.$relative);

    $exitCode = Artisan::call('erin:ops:pilot-drill', [
        '--scenario' => 'pass',
        '--confirm' => 'SYNTHETIC_LOCAL_ONLY',
        '--output' => $relative,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and($path)->toBeFile()
        ->and($path.'.sha256')->toBeFile()
        ->and(fileperms($path) & 0777)->toBe(0640)
        ->and(fileperms($path.'.sha256') & 0777)->toBe(0640)
        ->and(trim((string) file_get_contents($path.'.sha256')))
        ->toBe(hash_file('sha256', $path).'  '.basename($path))
        ->and(Artisan::output())->toContain(
            '"classification": "LOCAL_SYNTHETIC_NOT_PRODUCTION_EVIDENCE"',
            '"production_gate_eligible": false',
        );

    $firstHash = hash_file('sha256', $path);
    $exitCode = Artisan::call('erin:ops:pilot-drill', [
        '--scenario' => 'tenant-leak',
        '--confirm' => 'SYNTHETIC_LOCAL_ONLY',
        '--output' => $relative,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(hash_file('sha256', $path))->toBe($firstHash)
        ->and(Artisan::output())->toContain('existiert bereits');
})->group('ops');

it('refuses sidecar symlinks and evidence directories that escape the allowed root', function () {
    config(['app.env' => 'testing']);
    $suffix = bin2hex(random_bytes(6));
    $relative = "pilot/symlink-{$suffix}.json";
    $path = storage_path('app/operations/evidence/'.$relative);
    $target = storage_path("framework/testing/pilot-sidecar-target-{$suffix}.txt");
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0750, true);
    }
    file_put_contents($target, 'unverändert');
    symlink($target, $path.'.sha256');

    try {
        $exitCode = Artisan::call('erin:ops:pilot-drill', [
            '--scenario' => 'pass',
            '--confirm' => 'SYNTHETIC_LOCAL_ONLY',
            '--output' => $relative,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and($path)->not->toBeFile()
            ->and(file_get_contents($target))->toBe('unverändert')
            ->and(Artisan::output())->toContain('exklusiv erstellt');
    } finally {
        if (is_link($path.'.sha256')) {
            unlink($path.'.sha256');
        }
        if (is_file($target)) {
            unlink($target);
        }
    }

    $root = storage_path('app/operations/evidence');
    $outside = storage_path("framework/testing/pilot-outside-{$suffix}");
    $link = $root."/pilot-link-{$suffix}";
    mkdir($outside, 0700, true);
    symlink($outside, $link);

    try {
        $exitCode = Artisan::call('erin:ops:pilot-drill', [
            '--scenario' => 'pass',
            '--confirm' => 'SYNTHETIC_LOCAL_ONLY',
            '--output' => "pilot-link-{$suffix}/escaped.json",
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and($outside.'/escaped.json')->not->toBeFile()
            ->and(Artisan::output())->toContain('Unsicheres Evidenzverzeichnis');
    } finally {
        if (is_link($link)) {
            unlink($link);
        }
        if (is_dir($outside)) {
            rmdir($outside);
        }
    }
})->group('ops');
