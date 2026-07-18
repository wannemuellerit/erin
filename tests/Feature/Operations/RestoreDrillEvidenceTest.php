<?php

use App\Services\Operations\LocalRestoreEvidenceValidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

function localRestoreEvidenceDirectory(): string
{
    $path = storage_path('framework/testing/restore-evidence-'.bin2hex(random_bytes(6)));
    mkdir($path, 0750, true);

    return $path;
}

/**
 * @return array<string, mixed>
 */
function validLocalRestoreEvidence(string $directory): array
{
    file_put_contents($directory.'/database.sql.enc', 'Salted__'.random_bytes(32));
    file_put_contents($directory.'/object-storage.tar.enc', 'Salted__'.random_bytes(32));
    file_put_contents($directory.'/database.sql.enc.hmac', hash('sha256', 'database-mac')."\n");
    file_put_contents($directory.'/object-storage.tar.enc.hmac', hash('sha256', 'object-mac')."\n");

    $operationStarted = now()->utc()->subSeconds(20);
    $databaseLastRecord = $operationStarted->copy()->addSecond();
    $databaseBackup = $operationStarted->copy()->addSeconds(2);
    $objectLastRecord = $operationStarted->copy()->addSeconds(2);
    $objectBackup = $operationStarted->copy()->addSeconds(3);
    $backup = $operationStarted->copy()->addSeconds(4);
    $started = $operationStarted->copy()->addSeconds(5);
    $database = $operationStarted->copy()->addSeconds(8);
    $objects = $operationStarted->copy()->addSeconds(12);
    $completed = $operationStarted->copy()->addSeconds(15);
    $manifest = hash('sha256', 'manifest');
    $databaseContent = hash('sha256', 'canonical-database-content');
    $databaseStructure = hash('sha256', 'canonical-database-structure');
    $objectKeys = hash('sha256', 'object-key-manifest');

    return [
        'schema_version' => 1,
        'evidence_type' => 'local_encrypted_restore_drill',
        'classification' => 'LOCAL_SYNTHETIC_NOT_PRODUCTION_EVIDENCE',
        'production_gate_eligible' => false,
        'drill_id' => 'local-restore-20260718T160000Z-1234abcd',
        'timing' => [
            'operation_started_at' => $operationStarted->format('Y-m-d\TH:i:s\Z'),
            'started_at' => $started->format('Y-m-d\TH:i:s\Z'),
            'backup_completed_at' => $backup->format('Y-m-d\TH:i:s\Z'),
            'database_backup_created_at' => $databaseBackup->format('Y-m-d\TH:i:s\Z'),
            'database_last_restored_record_at' => $databaseLastRecord->format('Y-m-d\TH:i:s\Z'),
            'database_restored_at' => $database->format('Y-m-d\TH:i:s\Z'),
            'object_storage_backup_created_at' => $objectBackup->format('Y-m-d\TH:i:s\Z'),
            'object_storage_last_restored_record_at' => $objectLastRecord->format('Y-m-d\TH:i:s\Z'),
            'object_storage_restored_at' => $objects->format('Y-m-d\TH:i:s\Z'),
            'completed_at' => $completed->format('Y-m-d\TH:i:s\Z'),
            'database_rpo_target_seconds' => 900,
            'database_rpo_achieved_seconds' => 4,
            'database_rto_target_seconds' => 7200,
            'database_rto_achieved_seconds' => 3,
            'object_storage_rpo_target_seconds' => 1800,
            'object_storage_rpo_achieved_seconds' => 3,
            'object_storage_rto_target_seconds' => 10800,
            'object_storage_rto_achieved_seconds' => 7,
        ],
        'encryption' => [
            'algorithm' => 'AES-256-CBC',
            'kdf' => 'PBKDF2-HMAC-SHA256',
            'key_derivation' => 'HMAC-SHA256-DOMAIN-SEPARATED',
            'integrity' => 'HMAC-SHA256-ENCRYPT-THEN-MAC',
            'iterations' => 600_000,
            'key_material_location' => 'docker-managed-tmpfs-volume',
            'key_material_volume_removed' => true,
            'physical_destruction_claimed' => false,
            'plaintext_workspace_location' => 'docker-managed-tmpfs-volume',
            'plaintext_workspace_volume_removed' => true,
            'plaintext_backup_retained' => false,
        ],
        'isolation' => [
            'internal_network' => true,
            'no_host_ports' => true,
            'mysql_tmpfs' => true,
            'object_storage_tmpfs' => true,
            'sensitive_scratch_tmpfs' => true,
            'sensitive_scratch_removed' => true,
            'source_environment_non_production' => true,
            'source_container_identity_unchanged' => true,
            'source_quiesced_during_snapshot' => true,
            'source_quiesce_released' => true,
            'source_maintenance_state_restored' => true,
            'source_background_services_restored' => true,
            'cleanup_verified' => true,
        ],
        'artifacts' => [
            'database' => [
                'file' => 'database.sql.enc',
                'encrypted' => true,
                'ciphertext_format' => 'openssl-salted-v1',
                'sha256' => hash_file('sha256', $directory.'/database.sql.enc'),
                'size_bytes' => filesize($directory.'/database.sql.enc'),
                'hmac_file' => 'database.sql.enc.hmac',
                'hmac_sha256' => hash_file('sha256', $directory.'/database.sql.enc.hmac'),
                'mac_verified_before_decryption' => true,
            ],
            'object_storage' => [
                'file' => 'object-storage.tar.enc',
                'encrypted' => true,
                'ciphertext_format' => 'openssl-salted-v1',
                'sha256' => hash_file('sha256', $directory.'/object-storage.tar.enc'),
                'size_bytes' => filesize($directory.'/object-storage.tar.enc'),
                'hmac_file' => 'object-storage.tar.enc.hmac',
                'hmac_sha256' => hash_file('sha256', $directory.'/object-storage.tar.enc.hmac'),
                'mac_verified_before_decryption' => true,
            ],
        ],
        'validation' => [
            'database' => [
                'migration_count' => 42,
                'table_count' => 70,
                'source_business_row_count' => 123,
                'restored_business_row_count' => 123,
                'source_business_manifest_sha256' => $manifest,
                'restored_business_manifest_sha256' => $manifest,
                'canonical_content_scope' => 'all-table-complete-insert-rows-sorted-from-encrypted-full-dump',
                'source_canonical_content_sha256' => $databaseContent,
                'restored_canonical_content_sha256' => $databaseContent,
                'structure_scope' => 'schema-routines-events-triggers-normalized-from-full-dump',
                'source_structure_sha256' => $databaseStructure,
                'restored_structure_sha256' => $databaseStructure,
                'drill_canary_verified' => true,
                'drill_canary_id' => 'erin-restore-canary-local-restore-20260718t160000z-1234abcd',
            ],
            'object_storage' => [
                'source_application_object_count' => 2,
                'database_object_reference_count' => 2,
                'database_object_reference_manifest_sha256' => $objectKeys,
                'application_object_key_manifest_sha256' => $objectKeys,
                'missing_database_referenced_objects' => 0,
                'orphan_application_objects' => 0,
                'source_count' => 3,
                'restored_count' => 3,
                'source_bytes' => 1234,
                'restored_bytes' => 1234,
                'source_manifest_sha256' => $manifest,
                'restored_manifest_sha256' => $manifest,
                'missing_objects' => 0,
                'unexpected_objects' => 0,
                'drill_canary_included' => true,
            ],
        ],
        'negative_controls' => [
            'wrong_key_rejected' => true,
            'tampered_ciphertext_rejected' => true,
            'tampered_mac_rejected' => true,
            'missing_object_detected' => true,
            'non_id_database_change_detected' => true,
            'missing_database_row_detected' => true,
            'missing_database_object_detected' => true,
            'orphan_storage_object_detected' => true,
            'empty_storage_with_database_reference_rejected' => true,
        ],
        'result' => [
            'status' => 'passed',
            'errors' => [],
        ],
        'external_gates' => [
            'independent_verification' => 'open',
            'dpo_approval' => 'open',
            'legal_approval' => 'open',
            'production_restore' => 'open',
        ],
    ];
}

function writeLocalRestoreEvidence(string $directory, array $evidence): string
{
    $path = $directory.'/evidence.json';
    file_put_contents($path, json_encode($evidence, JSON_THROW_ON_ERROR));
    file_put_contents(
        $path.'.sha256',
        hash_file('sha256', $path).'  '.basename($path)."\n",
    );

    return $path;
}

it('accepts complete local restore evidence while keeping every external gate open', function () {
    $directory = localRestoreEvidenceDirectory();
    $path = writeLocalRestoreEvidence($directory, validLocalRestoreEvidence($directory));

    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);

    expect($result)
        ->status->toBe('passed')
        ->classification->toBe('LOCAL_SYNTHETIC_NOT_PRODUCTION_EVIDENCE')
        ->errors->toBeEmpty();
})->group('ops');

it('detects tampered encrypted artifacts and unsafe artifact paths', function () {
    $directory = localRestoreEvidenceDirectory();
    $evidence = validLocalRestoreEvidence($directory);
    $path = writeLocalRestoreEvidence($directory, $evidence);
    file_put_contents($directory.'/database.sql.enc', 'tampered', FILE_APPEND);

    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);
    expect($result['errors'])->toContain(
        'database_artifact_tampered',
        'database_artifact_size_mismatch',
    );

    $evidence['artifacts']['database']['file'] = '../database.sql.enc';
    writeLocalRestoreEvidence($directory, $evidence);
    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);

    expect($result['errors'])->toContain('database_artifact_name_invalid');
})->group('ops');

it('rejects missing or forged evidence and HMAC sidecars plus plaintext artifacts', function () {
    $directory = localRestoreEvidenceDirectory();
    $evidence = validLocalRestoreEvidence($directory);
    $path = writeLocalRestoreEvidence($directory, $evidence);

    unlink($path.'.sha256');
    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);
    expect($result['errors'])->toContain('evidence_checksum_missing_or_unsafe');

    writeLocalRestoreEvidence($directory, $evidence);
    file_put_contents($path.'.sha256', str_repeat('0', 64).'  evidence.json'."\n");
    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);
    expect($result['errors'])->toContain('evidence_checksum_mismatch');

    writeLocalRestoreEvidence($directory, $evidence);
    file_put_contents($directory.'/database.sql.enc.hmac', str_repeat('f', 64)."\n");
    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);
    expect($result['errors'])->toContain('database_artifact_hmac_sidecar_tampered');

    file_put_contents($directory.'/database.sql.enc', 'not-encrypted');
    $evidence['artifacts']['database']['sha256'] = hash_file(
        'sha256',
        $directory.'/database.sql.enc',
    );
    $evidence['artifacts']['database']['size_bytes'] = filesize(
        $directory.'/database.sql.enc',
    );
    $evidence['artifacts']['database']['hmac_sha256'] = hash_file(
        'sha256',
        $directory.'/database.sql.enc.hmac',
    );
    $path = writeLocalRestoreEvidence($directory, $evidence);
    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);
    expect($result['errors'])->toContain('database_ciphertext_header_invalid');
})->group('ops');

it('rejects local evidence that claims production approval or closed external gates', function () {
    $directory = localRestoreEvidenceDirectory();
    $evidence = validLocalRestoreEvidence($directory);
    $evidence['production_gate_eligible'] = true;
    $evidence['classification'] = 'PRODUCTION';
    $evidence['external_gates']['dpo_approval'] = 'approved';
    $path = writeLocalRestoreEvidence($directory, $evidence);

    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);

    expect($result['errors'])->toContain(
        'classification_invalid',
        'local_evidence_must_not_unlock_production',
        'external_gate_dpo_approval_must_remain_open',
    );
})->group('ops');

it('rejects clock rollback, future timestamps, manipulated measurements and missed targets', function () {
    $directory = localRestoreEvidenceDirectory();
    $evidence = validLocalRestoreEvidence($directory);
    $evidence['timing']['database_restored_at'] = now()
        ->utc()
        ->subMinute()
        ->format('Y-m-d\TH:i:s\Z');
    $evidence['timing']['completed_at'] = now()
        ->utc()
        ->addMinutes(10)
        ->format('Y-m-d\TH:i:s\Z');
    $evidence['timing']['database_rto_achieved_seconds'] = 999;
    $evidence['timing']['database_rto_target_seconds'] = 10;
    $path = writeLocalRestoreEvidence($directory, $evidence);

    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);

    expect($result['errors'])->toContain(
        'timing_clock_rollback_detected',
        'timing_completed_at_future',
        'database_rto_measurement_mismatch',
        'database_rto_target_missed',
    );
})->group('ops');

it('rejects missing objects, manifest drift, weak encryption and skipped negative controls', function () {
    $directory = localRestoreEvidenceDirectory();
    $evidence = validLocalRestoreEvidence($directory);
    $evidence['validation']['object_storage']['restored_count'] = 2;
    $evidence['validation']['object_storage']['restored_manifest_sha256'] = hash('sha256', 'other');
    $evidence['validation']['object_storage']['missing_objects'] = 1;
    $evidence['encryption']['iterations'] = 1;
    $evidence['encryption']['plaintext_backup_retained'] = true;
    $evidence['negative_controls']['wrong_key_rejected'] = false;
    $path = writeLocalRestoreEvidence($directory, $evidence);

    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);

    expect($result['errors'])->toContain(
        'object_count_mismatch',
        'object_manifest_mismatch',
        'objects_missing',
        'encryption_iterations_too_low',
        'plaintext_backup_retained',
        'negative_control_wrong_key_rejected_failed',
    );
})->group('ops');

it('rejects business-data loss, a missing database canary and manipulated RPO measurements', function () {
    $directory = localRestoreEvidenceDirectory();
    $evidence = validLocalRestoreEvidence($directory);
    $evidence['validation']['database']['restored_business_row_count'] = 122;
    $evidence['validation']['database']['restored_business_manifest_sha256'] = hash(
        'sha256',
        'different',
    );
    $evidence['validation']['database']['drill_canary_verified'] = false;
    $evidence['validation']['database']['restored_canonical_content_sha256'] = hash(
        'sha256',
        'non-id-content-changed',
    );
    $evidence['validation']['database']['restored_structure_sha256'] = hash(
        'sha256',
        'schema-or-trigger-changed',
    );
    $evidence['negative_controls']['missing_database_row_detected'] = false;
    $evidence['timing']['database_rpo_achieved_seconds'] = 0;
    $path = writeLocalRestoreEvidence($directory, $evidence);

    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);

    expect($result['errors'])->toContain(
        'database_business_row_count_mismatch',
        'database_business_manifest_mismatch',
        'database_canonical_content_mismatch',
        'database_structure_mismatch',
        'database_drill_canary_missing',
        'database_rpo_measurement_mismatch',
        'negative_control_missing_database_row_detected_failed',
    );
})->group('ops');

it('rejects a snapshot whose source quiesce or restoration is unproven', function () {
    $directory = localRestoreEvidenceDirectory();
    $evidence = validLocalRestoreEvidence($directory);
    $evidence['isolation']['source_quiesced_during_snapshot'] = false;
    $evidence['isolation']['source_quiesce_released'] = false;
    $evidence['isolation']['source_maintenance_state_restored'] = false;
    $evidence['isolation']['source_background_services_restored'] = false;
    $path = writeLocalRestoreEvidence($directory, $evidence);

    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);

    expect($result['errors'])->toContain(
        'isolation_source_quiesced_during_snapshot_not_verified',
        'isolation_source_quiesce_released_not_verified',
        'isolation_source_maintenance_state_restored_not_verified',
        'isolation_source_background_services_restored_not_verified',
    );
})->group('ops');

it('rejects missing database objects, storage orphans and an empty bucket with references', function () {
    $directory = localRestoreEvidenceDirectory();
    $evidence = validLocalRestoreEvidence($directory);
    $evidence['validation']['object_storage']['database_object_reference_count'] = 3;
    $evidence['validation']['object_storage']['missing_database_referenced_objects'] = 1;
    $evidence['validation']['object_storage']['orphan_application_objects'] = 1;
    $evidence['validation']['object_storage']['application_object_key_manifest_sha256'] = hash(
        'sha256',
        'different-object-keys',
    );
    $evidence['negative_controls']['missing_database_object_detected'] = false;
    $evidence['negative_controls']['orphan_storage_object_detected'] = false;
    $evidence['negative_controls']['empty_storage_with_database_reference_rejected'] = false;
    $path = writeLocalRestoreEvidence($directory, $evidence);

    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);

    expect($result['errors'])->toContain(
        'database_object_reference_count_mismatch',
        'database_object_reference_manifest_mismatch',
        'database_referenced_objects_missing',
        'orphan_application_objects_present',
        'negative_control_missing_database_object_detected_failed',
        'negative_control_orphan_storage_object_detected_failed',
        'negative_control_empty_storage_with_database_reference_rejected_failed',
    );
})->group('ops');

it('allows an empty application bucket only when the database has no private object references', function () {
    $directory = localRestoreEvidenceDirectory();
    $evidence = validLocalRestoreEvidence($directory);
    $emptyManifest = hash('sha256', '');
    $evidence['validation']['object_storage']['source_application_object_count'] = 0;
    $evidence['validation']['object_storage']['database_object_reference_count'] = 0;
    $evidence['validation']['object_storage']['database_object_reference_manifest_sha256'] = $emptyManifest;
    $evidence['validation']['object_storage']['application_object_key_manifest_sha256'] = $emptyManifest;
    $path = writeLocalRestoreEvidence($directory, $evidence);

    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);

    expect($result['status'])->toBe('passed')
        ->and($result['errors'])->toBeEmpty();
})->group('ops');

it('rejects future and stale individual backup timestamps even when completion looks current', function () {
    $directory = localRestoreEvidenceDirectory();
    $evidence = validLocalRestoreEvidence($directory);
    $evidence['timing']['database_backup_created_at'] = now()
        ->utc()
        ->addMinutes(10)
        ->format('Y-m-d\TH:i:s\Z');
    $evidence['timing']['object_storage_last_restored_record_at'] = now()
        ->utc()
        ->subDays(91)
        ->format('Y-m-d\TH:i:s\Z');
    $path = writeLocalRestoreEvidence($directory, $evidence);

    $result = app(LocalRestoreEvidenceValidator::class)->validateFile($path);

    expect($result['errors'])->toContain(
        'timing_database_backup_created_at_future',
        'timing_object_storage_last_restored_record_at_stale',
        'database_backup_after_drill_start',
    );
})->group('ops');

it('returns only error codes for malformed evidence and does not leak artifact contents', function () {
    $directory = localRestoreEvidenceDirectory();
    $path = $directory.'/evidence.json';
    file_put_contents($path, '{secret-token-that-must-not-appear');
    file_put_contents(
        $path.'.sha256',
        hash_file('sha256', $path).'  evidence.json'."\n",
    );

    $exitCode = Artisan::call('erin:ops:restore-evidence:verify', [
        'path' => $path,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain(
            '"status": "failed"',
            'evidence_json_invalid',
        )
        ->not->toContain('secret-token-that-must-not-appear');
})->group('ops');

it('confines sensitive work to tmpfs and atomically publishes a complete evidence package', function () {
    $script = File::get(base_path('scripts/ops/local-encrypted-restore-drill.sh'));

    expect($script)
        ->toContain(
            'container_user="$(id -u):$(id -g)"',
            '--user "$container_user"',
            '--opt type=tmpfs',
            '--opt device=tmpfs',
            '--opt o=size=2147483648,nosuid,nodev,noexec',
            'key_material_location: "docker-managed-tmpfs-volume"',
            'physical_destruction_claimed: false',
            '--order-by-primary',
            '--skip-comments',
            '| LC_ALL=C sort',
            'source_quiesced_during_snapshot',
            'source_quiesce_released',
            'release_source_quiesce || cleanup_status=1',
            'docker compose -f "$compose_file" stop --timeout 60 queue scheduler',
            'docker pause "$source_laravel_id"',
            'cat > /scratch/source-database.sql',
            '/scratch/source-database.sql',
            'restore_object_path',
            'cleanup_source_object_canary',
            'source_canonical_content_sha256',
            'source_structure_sha256',
            'non_id_database_change_detected',
            'missing_database_row_detected',
            'SELECT path FROM candidate_documents',
            'SELECT path FROM support_ticket_attachments',
            'missing_database_referenced_objects',
            'orphan_application_objects',
            'find "$output_dir" -depth -mindepth 1 -delete',
            "WHERE event <> '\${database_canary_id}'",
            'außer dem Drill-Canary keine prüfbaren fachlichen Datensätze',
            '-e "OBJECT_KEY=$first_object_key"',
            'mc rm "restore/$AWS_BUCKET/$OBJECT_KEY"',
            'mc cp "/scratch/restored-objects/$OBJECT_KEY" "restore/$AWS_BUCKET/$OBJECT_KEY"',
            'sha256sum evidence.json > evidence.json.sha256.part',
            'mv -nT "$evidence_file.sha256.part" "$evidence_file.sha256"',
            'mv -nT "$output_dir" "$final_output_dir"',
            'evidence_finalized=true',
            'trap - EXIT',
        )
        ->not->toContain(
            'restore/$AWS_BUCKET/$first_object_key',
            'ephemeral_key_destroyed: true',
            'mktemp -d /tmp/erin-restore-drill',
        );
})->group('ops');
