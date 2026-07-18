<?php

use App\Services\Operations\GovernanceAttestationPayload;
use App\Services\Operations\LaunchEvidenceValidator;
use App\Services\Operations\SecurityBaselineAudit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

$governanceAttestationDirectories = [];

afterEach(function () use (&$governanceAttestationDirectories): void {
    foreach ($governanceAttestationDirectories as $directory) {
        foreach (['attestation.json', 'trust-root.json'] as $filename) {
            $path = $directory.'/'.$filename;
            if (is_file($path) && ! is_link($path)) {
                unlink($path);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
    $governanceAttestationDirectories = [];
});

/**
 * @param  array<string, mixed>  $evidence
 * @return array{
 *     directory: string,
 *     attestation_path: string,
 *     trust_root_path: string,
 *     trust_root_sha256: string
 * }
 */
function configureErinGovernanceAttestation(
    array $evidence,
    string $keyStatus = 'active',
): array {
    global $governanceAttestationDirectories;

    $directory = storage_path(
        'framework/testing/governance-attestation-'.Str::lower(Str::random(16)),
    );
    mkdir($directory, 0700, true);
    $governanceAttestationDirectories[] = $directory;
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $now = now()->utc();
    $keyId = 'governance-test-key';
    $issuer = 'erin-test-governance-authority';
    $attestation = [
        'schema_version' => 1,
        'type' => 'erin_launch_governance_attestation',
        'algorithm' => 'Ed25519',
        'issuer' => $issuer,
        'key_id' => $keyId,
        'issued_at' => $now->copy()->format('Y-m-d\TH:i:s\Z'),
        'expires_at' => $now->copy()->addHour()->format('Y-m-d\TH:i:s\Z'),
        'release_id' => $evidence['release']['id'],
        'commit_sha' => $evidence['release']['commit_sha'],
        'evidence_sha256' => app(GovernanceAttestationPayload::class)
            ->evidenceDigest($evidence),
    ];
    $attestation['signature'] = base64_encode(sodium_crypto_sign_detached(
        app(GovernanceAttestationPayload::class)->signingMessage($attestation),
        $secretKey,
    ));
    $trustRoot = [
        'schema_version' => 1,
        'issuer' => $issuer,
        'keys' => [[
            'key_id' => $keyId,
            'algorithm' => 'Ed25519',
            'status' => $keyStatus,
            'public_key' => base64_encode($publicKey),
            'not_before' => $now->copy()->subDay()->format('Y-m-d\TH:i:s\Z'),
            'not_after' => $now->copy()->addDay()->format('Y-m-d\TH:i:s\Z'),
        ]],
    ];
    $attestationPath = $directory.'/attestation.json';
    $trustRootPath = $directory.'/trust-root.json';
    file_put_contents($attestationPath, json_encode($attestation, JSON_THROW_ON_ERROR));
    file_put_contents($trustRootPath, json_encode($trustRoot, JSON_THROW_ON_ERROR));
    chmod($attestationPath, 0600);
    chmod($trustRootPath, 0600);
    config([
        'operations.governance_attestation' => [
            'attestation_path' => $attestationPath,
            'trust_root_path' => $trustRootPath,
            'secret_root' => $directory,
            'maximum_lifetime_hours' => 24,
        ],
    ]);

    return [
        'directory' => $directory,
        'attestation_path' => $attestationPath,
        'trust_root_path' => $trustRootPath,
        'trust_root_sha256' => (string) hash_file('sha256', $trustRootPath),
    ];
}

/**
 * @return list<array{
 *     id: string,
 *     status: 'pass'|'warn'|'fail',
 *     message: string,
 *     errors: list<string>
 * }>
 */
function erinGovernanceChecks(?string $pinnedFingerprint = null): array
{
    if ($pinnedFingerprint === null) {
        $trustRootPath = config('operations.governance_attestation.trust_root_path');
        $hash = is_string($trustRootPath) && is_file($trustRootPath)
            ? hash_file('sha256', $trustRootPath)
            : false;
        $pinnedFingerprint = is_string($hash) ? $hash : null;
    }

    return $pinnedFingerprint === null
        ? app(LaunchEvidenceValidator::class)->checks()
        : app(LaunchEvidenceValidator::class)
            ->checksAgainstSyntheticTestPin($pinnedFingerprint);
}

function validErinLaunchEvidence(): array
{
    $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
    $started = now()->utc()->subMinutes(10)->format('Y-m-d\TH:i:s\Z');
    $completed = now()->utc()->subMinutes(5)->format('Y-m-d\TH:i:s\Z');
    $databaseBackup = now()->utc()->subMinutes(12)->format('Y-m-d\TH:i:s\Z');
    $databaseRecord = now()->utc()->subMinutes(13)->format('Y-m-d\TH:i:s\Z');
    $databaseRestored = now()->utc()->subMinutes(8)->format('Y-m-d\TH:i:s\Z');
    $objectBackup = now()->utc()->subMinutes(12)->format('Y-m-d\TH:i:s\Z');
    $objectRecord = now()->utc()->subMinutes(13)->format('Y-m-d\TH:i:s\Z');
    $objectRestored = now()->utc()->subMinutes(7)->format('Y-m-d\TH:i:s\Z');
    $releaseId = 'erin-2026.07.18-rc1';
    $commit = '0123456789abcdef0123456789abcdef01234567';

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
            'drill_started_at' => $started,
            'drill_completed_at' => $completed,
            'database_backup_created_at' => $databaseBackup,
            'database_last_restored_record_at' => $databaseRecord,
            'database_restored_at' => $databaseRestored,
            'object_storage_backup_created_at' => $objectBackup,
            'object_storage_last_restored_record_at' => $objectRecord,
            'object_storage_restored_at' => $objectRestored,
            'release_id' => $releaseId,
            'database_rpo_target_minutes' => '15',
            'database_rpo_achieved_minutes' => '3',
            'database_rto_target_minutes' => '120',
            'database_rto_achieved_minutes' => '2',
            'object_storage_rpo_target_minutes' => '30',
            'object_storage_rpo_achieved_minutes' => '3',
            'object_storage_rto_target_minutes' => '180',
            'object_storage_rto_achieved_minutes' => '3',
            'encrypted_backup_verified' => true,
            'isolated_restore_verified' => true,
            'scope' => 'production',
            'production_gate_eligible' => true,
            'independently_verified' => true,
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
            'independent_review_verified' => true,
            'penetration_test_verified' => true,
        ],
        'dpo_approval' => [
            'reference' => 'https://evidence.wannemueller.dev/privacy/approval-4711',
            'approved_by' => 'Daria Datenschutz <daria.datenschutz@wannemueller.dev>',
            'approved_at' => $timestamp,
            'release_id' => $releaseId,
            'status' => 'approved',
            'authority_verified' => true,
        ],
        'legal_approval' => [
            'reference' => 'https://evidence.wannemueller.dev/legal/approval-4711',
            'approved_by' => 'Lena Recht <lena.recht@wannemueller.dev>',
            'approved_at' => $timestamp,
            'release_id' => $releaseId,
            'status' => 'approved',
            'authority_verified' => true,
        ],
        'pilot' => [
            'reference' => 'https://evidence.wannemueller.dev/pilot/decision-4711',
            'owner' => 'Paul Pilot <paul.pilot@wannemueller.dev>',
            'deputy' => 'Dana Vertretung <dana.vertretung@wannemueller.dev>',
            'decision_by' => 'Nora Freigabe <nora.freigabe@wannemueller.dev>',
            'decision_at' => $timestamp,
            'started_at' => $started,
            'release_id' => $releaseId,
            'plan_reference' => 'https://evidence.wannemueller.dev/pilot/plan-4711',
            'acceptance_reference' => 'https://evidence.wannemueller.dev/pilot/acceptance-4711',
            'rollback_reference' => 'https://evidence.wannemueller.dev/pilot/rollback-4711',
            'status' => 'approved',
            'synthetic' => false,
            'participant_consent_verified' => true,
            'stop_criteria_tested' => true,
            'rollback_tested' => true,
        ],
    ];
}

function configurePassingSecurityBaseline(): void
{
    $buildSha = '0123456789abcdef0123456789abcdef01234567';

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
    configureErinGovernanceAttestation($evidence);

    $checks = erinGovernanceChecks();

    expect($checks)->toHaveCount(7)
        ->and(collect($checks)->where('status', 'pass'))->toHaveCount(7)
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
    configureErinGovernanceAttestation($evidence);

    $checks = collect(erinGovernanceChecks());
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
    $evidence['security_review']['commit_sha'] = '1123456789abcdef0123456789abcdef01234567';
    $evidence['security_review']['open_high_findings'] = '1';
    $evidence['backup_restore']['database_rto_achieved_minutes'] = '121';

    config([
        'app.env' => 'production',
        'operations.build.sha' => $evidence['release']['commit_sha'],
        'operations.launch_evidence' => $evidence,
    ]);
    configureErinGovernanceAttestation($evidence);

    $checks = collect(erinGovernanceChecks());
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
        'operations.build.sha' => '1123456789abcdef0123456789abcdef01234567',
        'operations.launch_evidence' => $evidence,
    ]);
    configureErinGovernanceAttestation($evidence);

    $release = collect(erinGovernanceChecks())
        ->firstWhere('id', 'evidence.release');

    expect($release['status'])->toBe('fail')
        ->and($release['errors'])->toContain('evidence_build_sha_mismatch');
})->group('ops');

it('fails closed without a separately provisioned governance trust root', function () {
    $evidence = validErinLaunchEvidence();
    config([
        'app.env' => 'production',
        'operations.build.sha' => $evidence['release']['commit_sha'],
        'operations.launch_evidence' => $evidence,
        'operations.governance_attestation' => [
            'attestation_path' => null,
            'trust_root_path' => null,
            'secret_root' => storage_path('framework/testing'),
            'maximum_lifetime_hours' => 24,
        ],
    ]);

    $attestation = collect(erinGovernanceChecks())
        ->firstWhere('id', 'evidence.attestation');

    expect($attestation['status'])->toBe('fail')
        ->and($attestation['errors'])->toContain(
            'attestation_path_missing',
            'attestation_trust_root_path_missing',
            'attestation_trust_root_build_pin_missing',
        );
})->group('ops');

it('rejects a forged signature, evidence drift and unsafe secret permissions', function () {
    $evidence = validErinLaunchEvidence();
    config([
        'app.env' => 'production',
        'operations.build.sha' => $evidence['release']['commit_sha'],
        'operations.launch_evidence' => $evidence,
    ]);
    $files = configureErinGovernanceAttestation($evidence);
    $attestation = json_decode(
        (string) file_get_contents($files['attestation_path']),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $attestation['signature'] = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES));
    file_put_contents(
        $files['attestation_path'],
        json_encode($attestation, JSON_THROW_ON_ERROR),
    );

    $check = collect(erinGovernanceChecks())
        ->firstWhere('id', 'evidence.attestation');
    expect($check['errors'])->toContain('attestation_signature_verification_failed');

    configureErinGovernanceAttestation($evidence);
    config(['operations.launch_evidence.pilot.status' => 'rejected']);
    $check = collect(erinGovernanceChecks())
        ->firstWhere('id', 'evidence.attestation');
    expect($check['errors'])->toContain('attestation_evidence_digest_mismatch');

    $evidence = validErinLaunchEvidence();
    config(['operations.launch_evidence' => $evidence]);
    $files = configureErinGovernanceAttestation($evidence);
    chmod($files['trust_root_path'], 0666);
    $check = collect(erinGovernanceChecks())
        ->firstWhere('id', 'evidence.attestation');
    expect($check['errors'])->toContain('attestation_trust_root_permissions_unsafe');
})->group('ops');

it('accepts a retiring rotation key but rejects a revoked key', function () {
    $evidence = validErinLaunchEvidence();
    config([
        'app.env' => 'production',
        'operations.build.sha' => $evidence['release']['commit_sha'],
        'operations.launch_evidence' => $evidence,
    ]);
    configureErinGovernanceAttestation($evidence, 'retiring');
    $check = collect(erinGovernanceChecks())
        ->firstWhere('id', 'evidence.attestation');
    expect($check['status'])->toBe('pass');

    configureErinGovernanceAttestation($evidence, 'revoked');
    $check = collect(erinGovernanceChecks())
        ->firstWhere('id', 'evidence.attestation');
    expect($check['errors'])->toContain('attestation_key_inactive');
})->group('ops');

it('binds declared RPO and RTO values to backup, record and restore timestamps', function () {
    $evidence = validErinLaunchEvidence();
    $evidence['backup_restore']['database_rpo_achieved_minutes'] = '0';
    $evidence['backup_restore']['database_rto_achieved_minutes'] = '1';
    $evidence['backup_restore']['object_storage_last_restored_record_at'] = now()
        ->utc()
        ->format('Y-m-d\TH:i:s\Z');
    config([
        'app.env' => 'production',
        'operations.build.sha' => $evidence['release']['commit_sha'],
        'operations.launch_evidence' => $evidence,
    ]);
    configureErinGovernanceAttestation($evidence);

    $backup = collect(erinGovernanceChecks())
        ->firstWhere('id', 'backup.restore_drill');

    expect($backup['errors'])->toContain(
        'database_rpo_measurement_mismatch',
        'database_rto_measurement_mismatch',
        'object_storage_record_after_backup',
    );
})->group('ops');

it('rejects a substituted trust root against the explicit test pin and ambiguous key ids', function () {
    $evidence = validErinLaunchEvidence();
    config([
        'app.env' => 'production',
        'operations.build.sha' => $evidence['release']['commit_sha'],
        'operations.launch_evidence' => $evidence,
    ]);
    $files = configureErinGovernanceAttestation($evidence);
    file_put_contents($files['trust_root_path'], "\n", FILE_APPEND);

    $check = collect(erinGovernanceChecks($files['trust_root_sha256']))
        ->firstWhere('id', 'evidence.attestation');
    expect($check['errors'])->toContain('attestation_trust_root_pin_mismatch');

    $files = configureErinGovernanceAttestation($evidence);
    $root = json_decode(
        (string) file_get_contents($files['trust_root_path']),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $root['keys'][] = $root['keys'][0];
    file_put_contents(
        $files['trust_root_path'],
        json_encode($root, JSON_THROW_ON_ERROR),
    );

    $check = collect(erinGovernanceChecks())
        ->firstWhere('id', 'evidence.attestation');
    expect($check['errors'])->toContain(
        'attestation_key_id_ambiguous',
        'attestation_trust_root_key_id_duplicate',
    );

    $files = configureErinGovernanceAttestation($evidence);
    $root = json_decode(
        (string) file_get_contents($files['trust_root_path']),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $unusedKey = $root['keys'][0];
    $unusedKey['key_id'] = 'unused-rotation-key';
    $root['keys'][] = $unusedKey;
    $root['keys'][] = $unusedKey;
    file_put_contents(
        $files['trust_root_path'],
        json_encode($root, JSON_THROW_ON_ERROR),
    );

    $check = collect(erinGovernanceChecks())
        ->firstWhere('id', 'evidence.attestation');
    expect($check['errors'])->toContain('attestation_trust_root_key_id_duplicate')
        ->not->toContain('attestation_key_id_ambiguous');
})->group('ops');

it('rejects stale, future and causally impossible backup measurements', function () {
    $evidence = validErinLaunchEvidence();
    $evidence['backup_restore']['database_backup_created_at'] = now()
        ->utc()
        ->addMinutes(10)
        ->format('Y-m-d\TH:i:s\Z');
    $evidence['backup_restore']['database_last_restored_record_at'] = now()
        ->utc()
        ->subDays(91)
        ->format('Y-m-d\TH:i:s\Z');
    $evidence['backup_restore']['object_storage_restored_at'] = now()
        ->utc()
        ->subMinutes(11)
        ->format('Y-m-d\TH:i:s\Z');
    config([
        'app.env' => 'production',
        'operations.build.sha' => $evidence['release']['commit_sha'],
        'operations.launch_evidence' => $evidence,
    ]);
    configureErinGovernanceAttestation($evidence);

    $backup = collect(erinGovernanceChecks())
        ->firstWhere('id', 'backup.restore_drill');

    expect($backup['errors'])->toContain(
        'database_backup_created_at_future_or_stale',
        'database_last_restored_record_at_future_or_stale',
        'database_backup_after_drill_start',
        'object_storage_restore_before_drill',
    );
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
            'ERIN_GOVERNANCE_TRUST_ROOT_SHA256: ${ERIN_GOVERNANCE_TRUST_ROOT_SHA256:?ERIN_GOVERNANCE_TRUST_ROOT_SHA256 must be set}',
            'AWS_ACCESS_KEY_ID: ${MINIO_APP_USER:?MINIO_APP_USER must be set}',
            'mc admin policy create local erin-app-bucket',
            'arn:aws:s3:::$${AWS_BUCKET}/*',
        )
        ->not->toContain('ERIN_APP_TAG:-latest');

    expect($dockerfile)->toBeString()
        ->toContain(
            'LABEL org.opencontainers.image.revision="${ERIN_BUILD_SHA}"',
            '> /app/.erin-build-sha',
            '> /app/.erin-governance-trust-root-sha256',
            'chmod 0444 /app/.erin-governance-trust-root-sha256',
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
        'operations.launch_evidence.release.commit_sha' => '0123456789abcdef0123456789abcdef01234567',
    ]);

    $exitCode = Artisan::call('erin:ops:security-audit', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"schema_version": 1')
        ->toContain('"status": "passed"')
        ->toContain('"build_sha": "0123456789abcdef0123456789abcdef01234567"')
        ->not->toContain('livekit-secret-must-not-appear')
        ->not->toContain((string) config('app.key'));
})->group('ops');
