<?php

use App\Services\Operations\LaunchEvidenceValidator;
use App\Services\Operations\SecurityBaselineAudit;
use Illuminate\Support\Facades\Artisan;

function validErinLaunchEvidence(): array
{
    $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
    $releaseId = 'erin-2026.07.18-rc1';
    $commit = str_repeat('a', 40);

    return [
        'release' => [
            'id' => $releaseId,
            'commit_sha' => $commit,
            'prepared_by' => 'Mara Release <mara.release@wannemueller.dev>',
        ],
        'backup_restore' => [
            'reference' => 'https://evidence.wannemueller.dev/restores/restore-4711',
            'verified_by' => 'Benedikt Restore <benedikt.restore@wannemueller.dev>',
            'verified_at' => $timestamp,
            'release_id' => $releaseId,
            'database_rpo_target_minutes' => '15',
            'database_rpo_achieved_minutes' => '8',
            'database_rto_target_minutes' => '120',
            'database_rto_achieved_minutes' => '74',
            'object_storage_rpo_target_minutes' => '30',
            'object_storage_rpo_achieved_minutes' => '18',
            'object_storage_rto_target_minutes' => '180',
            'object_storage_rto_achieved_minutes' => '96',
            'encrypted_backup_verified' => true,
            'isolated_restore_verified' => true,
        ],
        'security_review' => [
            'reference' => 'https://evidence.wannemueller.dev/security/review-4711',
            'reviewed_by' => 'Sofia Sicherheit <sofia.sicherheit@wannemueller.dev>',
            'reviewed_at' => $timestamp,
            'release_id' => $releaseId,
            'commit_sha' => $commit,
            'automated_evidence_reference' => 'https://evidence.wannemueller.dev/security/ci-4711',
            'open_critical_findings' => '0',
            'open_high_findings' => '0',
        ],
        'dpo_approval' => [
            'reference' => 'https://evidence.wannemueller.dev/privacy/approval-4711',
            'approved_by' => 'Daria Datenschutz <daria.datenschutz@wannemueller.dev>',
            'approved_at' => $timestamp,
            'release_id' => $releaseId,
            'status' => 'approved',
        ],
        'legal_approval' => [
            'reference' => 'https://evidence.wannemueller.dev/legal/approval-4711',
            'approved_by' => 'Lena Recht <lena.recht@wannemueller.dev>',
            'approved_at' => $timestamp,
            'release_id' => $releaseId,
            'status' => 'approved',
        ],
        'pilot' => [
            'reference' => 'https://evidence.wannemueller.dev/pilot/decision-4711',
            'owner' => 'Paul Pilot <paul.pilot@wannemueller.dev>',
            'deputy' => 'Dana Vertretung <dana.vertretung@wannemueller.dev>',
            'decision_by' => 'Nora Freigabe <nora.freigabe@wannemueller.dev>',
            'decision_at' => $timestamp,
            'release_id' => $releaseId,
            'plan_reference' => 'https://evidence.wannemueller.dev/pilot/plan-4711',
            'acceptance_reference' => 'https://evidence.wannemueller.dev/pilot/acceptance-4711',
            'rollback_reference' => 'https://evidence.wannemueller.dev/pilot/rollback-4711',
            'status' => 'approved',
        ],
    ];
}

function configurePassingSecurityBaseline(): void
{
    $buildSha = str_repeat('a', 40);

    config([
        'app.env' => 'production',
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.debug' => false,
        'app.demo_mode' => false,
        'app.url' => 'https://erin.wannemueller.dev',
        'session.secure' => true,
        'session.http_only' => true,
        'session.encrypt' => true,
        'session.same_site' => 'lax',
        'queue.default' => 'redis',
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'filesystems.disks.private.driver' => 's3',
        'filesystems.disks.private.visibility' => 'private',
        'filesystems.disks.private.throw' => true,
        'filesystems.disks.private.key' => 'erin-app',
        'operations.build.sha' => $buildSha,
        'operations.build.image_tag' => $buildSha,
        'operations.network.internal_subnet' => '172.30.0.0/24',
        'operations.network.trusted_proxies' => ['172.30.0.0/24'],
        'operations.storage.minio_app_user' => 'erin-app',
        'fortify.limiters.login' => 'login',
        'fortify.limiters.two-factor' => 'two-factor',
        'fortify.limiters.passkeys' => 'passkeys',
        'services.openai.store' => false,
        'services.openai.document_ai_enabled' => false,
        'services.openai.eu_data_controls' => false,
        'services.livekit.url' => 'wss://livekit.wannemueller.dev',
        'services.livekit.api_key' => 'livekit-key',
        'services.livekit.api_secret' => 'livekit-secret-must-not-appear',
        'services.livekit.e2ee_required' => true,
        'services.livekit.region' => 'eu',
        'services.livekit.token_ttl_minutes' => 10,
        'reverb.apps.apps.0.allowed_origins' => ['erin.wannemueller.dev'],
        'reverb.apps.apps.0.rate_limiting' => [
            'enabled' => true,
            'max_attempts' => 60,
            'decay_seconds' => 60,
            'terminate_on_limit' => true,
        ],
    ]);
}

it('accepts only complete fresh release-bound evidence with separated roles', function () {
    $evidence = validErinLaunchEvidence();
    config([
        'app.env' => 'production',
        'operations.build.sha' => $evidence['release']['commit_sha'],
        'operations.launch_evidence' => $evidence,
    ]);

    $checks = app(LaunchEvidenceValidator::class)->checks();

    expect($checks)->toHaveCount(6)
        ->and(collect($checks)->where('status', 'pass'))->toHaveCount(6)
        ->and(collect($checks)->pluck('errors')->flatten())->toBeEmpty();
})->group('ops');

it('rejects placeholders, self-approval and DPO/legal role reuse', function () {
    $evidence = validErinLaunchEvidence();
    $evidence['dpo_approval']['reference'] = 'https://example.com/todo';
    $evidence['dpo_approval']['approved_by'] = $evidence['release']['prepared_by'];
    $evidence['legal_approval']['approved_by'] = $evidence['dpo_approval']['approved_by'];

    config([
        'app.env' => 'production',
        'operations.build.sha' => $evidence['release']['commit_sha'],
        'operations.launch_evidence' => $evidence,
    ]);

    $checks = collect(app(LaunchEvidenceValidator::class)->checks());
    $dpo = $checks->firstWhere('id', 'dpo.approval');
    $legal = $checks->firstWhere('id', 'legal.approval');

    expect($dpo['status'])->toBe('fail')
        ->and($dpo['errors'])->toContain(
            'reference_invalid',
            'dpo_self_approval',
            'dpo_legal_roles_not_separated',
        )
        ->and($legal['status'])->toBe('fail')
        ->and($legal['errors'])->toContain('dpo_legal_roles_not_separated');
})->group('ops');

it('rejects stale evidence, commit drift, open findings and missed restore targets', function () {
    $evidence = validErinLaunchEvidence();
    $evidence['security_review']['reviewed_at'] = now()
        ->utc()
        ->subDays(31)
        ->format('Y-m-d\TH:i:s\Z');
    $evidence['security_review']['commit_sha'] = str_repeat('b', 40);
    $evidence['security_review']['open_high_findings'] = '1';
    $evidence['backup_restore']['database_rto_achieved_minutes'] = '121';

    config([
        'app.env' => 'production',
        'operations.build.sha' => $evidence['release']['commit_sha'],
        'operations.launch_evidence' => $evidence,
    ]);

    $checks = collect(app(LaunchEvidenceValidator::class)->checks());
    $security = $checks->firstWhere('id', 'security.review');
    $backup = $checks->firstWhere('id', 'backup.restore_drill');

    expect($security['errors'])->toContain(
        'reviewed_at_invalid_or_stale',
        'review_commit_mismatch',
        'open_high_findings',
    )
        ->and($backup['errors'])->toContain('database_rto_target_missed');
})->group('ops');

it('rejects evidence that does not match the SHA embedded in the image', function () {
    $evidence = validErinLaunchEvidence();
    config([
        'app.env' => 'production',
        'operations.build.sha' => str_repeat('b', 40),
        'operations.launch_evidence' => $evidence,
    ]);

    $release = collect(app(LaunchEvidenceValidator::class)->checks())
        ->firstWhere('id', 'evidence.release');

    expect($release['status'])->toBe('fail')
        ->and($release['errors'])->toContain('evidence_build_sha_mismatch');
})->group('ops');

it('passes the complete technical production security baseline', function () {
    configurePassingSecurityBaseline();

    $checks = app(SecurityBaselineAudit::class)->checks();

    expect($checks)->toHaveCount(15)
        ->and(collect($checks)->where('status', 'fail'))->toBeEmpty();
})->group('ops');

it('fails insecure sessions, proxy trust, moving image tags, wildcard realtime origins and non-EU video', function () {
    configurePassingSecurityBaseline();
    config([
        'session.encrypt' => false,
        'operations.build.image_tag' => 'latest',
        'operations.network.trusted_proxies' => ['*'],
        'reverb.apps.apps.0.allowed_origins' => ['*'],
        'services.livekit.url' => 'ws://livekit.internal',
        'services.livekit.region' => 'us',
        'services.livekit.e2ee_required' => false,
    ]);

    $failed = collect(app(SecurityBaselineAudit::class)->checks())
        ->where('status', 'fail')
        ->pluck('id');

    expect($failed)->toContain(
        'session.hardening',
        'release.immutable_image',
        'proxy.trust_boundary',
        'reverb.abuse_protection',
        'livekit.security',
    );
})->group('ops');

it('pins the Zammad webhook body limit and strips untrusted forwarded headers in Nginx', function () {
    $nginx = file_get_contents(base_path('docker/production/nginx.conf'));

    expect($nginx)->toBeString()
        ->toContain(
            'location = /integrations/zammad/webhook {',
            'client_max_body_size 2m;',
            'proxy_set_header X-Forwarded-For $remote_addr;',
            'proxy_set_header Forwarded "";',
            'fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr;',
            'fastcgi_param HTTP_FORWARDED "";',
        )
        ->not->toContain('$proxy_add_x_forwarded_for');
})->group('ops');

it('binds production images to the build SHA and provisions only bucket-scoped MinIO app access', function () {
    $compose = file_get_contents(base_path('compose.production.yaml'));
    $dockerfile = file_get_contents(base_path('docker/production/Dockerfile'));
    $entrypoint = file_get_contents(base_path('docker/production/entrypoint.sh'));

    expect($compose)->toBeString()
        ->toContain(
            'image: ${ERIN_APP_IMAGE:-erin-app}:${ERIN_APP_TAG:?ERIN_APP_TAG must be set}',
            'ERIN_BUILD_SHA: ${ERIN_BUILD_SHA:?ERIN_BUILD_SHA must be set}',
            'AWS_ACCESS_KEY_ID: ${MINIO_APP_USER:?MINIO_APP_USER must be set}',
            'mc admin policy create local erin-app-bucket',
            'arn:aws:s3:::$${AWS_BUCKET}/*',
        )
        ->not->toContain('ERIN_APP_TAG:-latest');

    expect($dockerfile)->toBeString()
        ->toContain(
            'LABEL org.opencontainers.image.revision="${ERIN_BUILD_SHA}"',
            '> /app/.erin-build-sha',
        );

    expect($entrypoint)->toBeString()
        ->toContain(
            '[ "${ERIN_BUILD_SHA:-}" != "$image_build_sha" ]',
            '[ "${ERIN_APP_TAG:-}" != "$image_build_sha" ]',
        );
})->group('ops');

it('emits a machine-readable security audit without credentials', function () {
    configurePassingSecurityBaseline();
    config([
        'operations.launch_evidence.release.id' => 'erin-2026.07.18-rc1',
        'operations.launch_evidence.release.commit_sha' => str_repeat('a', 40),
    ]);

    $exitCode = Artisan::call('erin:ops:security-audit', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"schema_version": 1')
        ->toContain('"status": "passed"')
        ->toContain('"build_sha": "'.str_repeat('a', 40).'"')
        ->not->toContain('livekit-secret-must-not-appear')
        ->not->toContain((string) config('app.key'));
})->group('ops');
