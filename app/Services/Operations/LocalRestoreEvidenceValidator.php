<?php

namespace App\Services\Operations;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class LocalRestoreEvidenceValidator
{
    /**
     * @return array{
     *     schema_version: int,
     *     status: 'passed'|'failed',
     *     classification: string|null,
     *     drill_id: string|null,
     *     errors: list<string>
     * }
     */
    public function validateFile(string $path): array
    {
        $errors = $this->evidenceSidecarErrors($path);
        $evidence = $this->readEvidence($path, $errors);

        if ($evidence !== null) {
            $errors = [...$errors, ...$this->validateEvidence($evidence, $path)];
        }

        $errors = array_values(array_unique($errors));

        return [
            'schema_version' => 1,
            'status' => $errors === [] ? 'passed' : 'failed',
            'classification' => is_array($evidence)
                && is_string($evidence['classification'] ?? null)
                    ? $evidence['classification']
                    : null,
            'drill_id' => is_array($evidence) && is_string($evidence['drill_id'] ?? null)
                ? $evidence['drill_id']
                : null,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function readEvidence(string $path, array &$errors): ?array
    {
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            $errors[] = 'evidence_file_missing_or_unsafe';

            return null;
        }

        $contents = file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            $errors[] = 'evidence_file_empty';

            return null;
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $errors[] = 'evidence_json_invalid';

            return null;
        }

        if (! is_array($decoded)) {
            $errors[] = 'evidence_document_invalid';

            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<string>
     */
    private function validateEvidence(array $evidence, string $path): array
    {
        $errors = [];

        if (($evidence['schema_version'] ?? null) !== 1) {
            $errors[] = 'schema_version_unsupported';
        }
        if (($evidence['evidence_type'] ?? null) !== 'local_encrypted_restore_drill') {
            $errors[] = 'evidence_type_invalid';
        }
        if (($evidence['classification'] ?? null) !== 'LOCAL_SYNTHETIC_NOT_PRODUCTION_EVIDENCE') {
            $errors[] = 'classification_invalid';
        }
        if (($evidence['production_gate_eligible'] ?? null) !== false) {
            $errors[] = 'local_evidence_must_not_unlock_production';
        }

        $drillId = $evidence['drill_id'] ?? null;
        if (
            ! is_string($drillId)
            || preg_match('/\Alocal-restore-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/', $drillId) !== 1
        ) {
            $errors[] = 'drill_id_invalid';
        }

        $timing = $this->section($evidence, 'timing');
        $errors = [...$errors, ...$this->timingErrors($timing)];

        $encryption = $this->section($evidence, 'encryption');
        if (($encryption['algorithm'] ?? null) !== 'AES-256-CBC') {
            $errors[] = 'encryption_algorithm_invalid';
        }
        if (($encryption['kdf'] ?? null) !== 'PBKDF2-HMAC-SHA256') {
            $errors[] = 'encryption_kdf_invalid';
        }
        if (($encryption['key_derivation'] ?? null) !== 'HMAC-SHA256-DOMAIN-SEPARATED') {
            $errors[] = 'encryption_key_derivation_invalid';
        }
        if (($encryption['integrity'] ?? null) !== 'HMAC-SHA256-ENCRYPT-THEN-MAC') {
            $errors[] = 'encryption_integrity_invalid';
        }
        if (! is_int($encryption['iterations'] ?? null) || $encryption['iterations'] < 600_000) {
            $errors[] = 'encryption_iterations_too_low';
        }
        if (($encryption['key_material_location'] ?? null) !== 'docker-managed-tmpfs-volume') {
            $errors[] = 'key_material_not_confined_to_tmpfs';
        }
        if (($encryption['key_material_volume_removed'] ?? null) !== true) {
            $errors[] = 'key_material_tmpfs_not_removed';
        }
        if (($encryption['physical_destruction_claimed'] ?? null) !== false) {
            $errors[] = 'unsupported_physical_destruction_claim';
        }
        if (($encryption['plaintext_workspace_location'] ?? null) !== 'docker-managed-tmpfs-volume') {
            $errors[] = 'plaintext_workspace_not_confined_to_tmpfs';
        }
        if (($encryption['plaintext_workspace_volume_removed'] ?? null) !== true) {
            $errors[] = 'plaintext_workspace_tmpfs_not_removed';
        }
        if (($encryption['plaintext_backup_retained'] ?? null) !== false) {
            $errors[] = 'plaintext_backup_retained';
        }

        $isolation = $this->section($evidence, 'isolation');
        foreach ([
            'internal_network',
            'no_host_ports',
            'mysql_tmpfs',
            'object_storage_tmpfs',
            'sensitive_scratch_tmpfs',
            'sensitive_scratch_removed',
            'source_environment_non_production',
            'source_container_identity_unchanged',
            'source_quiesced_during_snapshot',
            'source_quiesce_released',
            'source_maintenance_state_restored',
            'source_background_services_restored',
            'cleanup_verified',
        ] as $flag) {
            if (($isolation[$flag] ?? null) !== true) {
                $errors[] = "isolation_{$flag}_not_verified";
            }
        }

        $artifacts = $this->section($evidence, 'artifacts');
        foreach (['database', 'object_storage'] as $artifactName) {
            $artifact = $this->section($artifacts, $artifactName);
            $errors = [
                ...$errors,
                ...$this->artifactErrors($artifact, dirname($path), $artifactName),
            ];
        }

        $validation = $this->section($evidence, 'validation');
        $database = $this->section($validation, 'database');
        if (! $this->positiveInteger($database['migration_count'] ?? null)) {
            $errors[] = 'database_migrations_missing';
        }
        if (! $this->positiveInteger($database['table_count'] ?? null)) {
            $errors[] = 'database_tables_missing';
        }
        $sourceBusinessRows = $database['source_business_row_count'] ?? null;
        $restoredBusinessRows = $database['restored_business_row_count'] ?? null;
        if (
            ! $this->positiveInteger($sourceBusinessRows)
            || $sourceBusinessRows !== $restoredBusinessRows
        ) {
            $errors[] = 'database_business_row_count_mismatch';
        }
        $sourceBusinessManifest = $database['source_business_manifest_sha256'] ?? null;
        $restoredBusinessManifest = $database['restored_business_manifest_sha256'] ?? null;
        if (
            ! is_string($sourceBusinessManifest)
            || preg_match('/\A[0-9a-f]{64}\z/', $sourceBusinessManifest) !== 1
            || ! is_string($restoredBusinessManifest)
            || ! hash_equals($sourceBusinessManifest, $restoredBusinessManifest)
        ) {
            $errors[] = 'database_business_manifest_mismatch';
        }
        if (($database['canonical_content_scope'] ?? null) !== 'all-table-complete-insert-rows-sorted-from-encrypted-full-dump') {
            $errors[] = 'database_canonical_content_scope_invalid';
        }
        $sourceCanonicalContent = $database['source_canonical_content_sha256'] ?? null;
        $restoredCanonicalContent = $database['restored_canonical_content_sha256'] ?? null;
        if (
            ! is_string($sourceCanonicalContent)
            || preg_match('/\A[0-9a-f]{64}\z/', $sourceCanonicalContent) !== 1
            || ! is_string($restoredCanonicalContent)
            || ! hash_equals($sourceCanonicalContent, $restoredCanonicalContent)
        ) {
            $errors[] = 'database_canonical_content_mismatch';
        }
        if (($database['structure_scope'] ?? null) !== 'schema-routines-events-triggers-normalized-from-full-dump') {
            $errors[] = 'database_structure_scope_invalid';
        }
        $sourceStructure = $database['source_structure_sha256'] ?? null;
        $restoredStructure = $database['restored_structure_sha256'] ?? null;
        if (
            ! is_string($sourceStructure)
            || preg_match('/\A[0-9a-f]{64}\z/', $sourceStructure) !== 1
            || ! is_string($restoredStructure)
            || ! hash_equals($sourceStructure, $restoredStructure)
        ) {
            $errors[] = 'database_structure_mismatch';
        }
        if (($database['drill_canary_verified'] ?? null) !== true) {
            $errors[] = 'database_drill_canary_missing';
        }
        if (
            ! is_string($database['drill_canary_id'] ?? null)
            || preg_match('/\Aerin-restore-canary-[a-z0-9-]{16,100}\z/', $database['drill_canary_id']) !== 1
        ) {
            $errors[] = 'database_drill_canary_id_invalid';
        }

        $objects = $this->section($validation, 'object_storage');
        $errors = [...$errors, ...$this->objectStorageErrors($objects)];

        $controls = $this->section($evidence, 'negative_controls');
        foreach ([
            'wrong_key_rejected',
            'tampered_ciphertext_rejected',
            'tampered_mac_rejected',
            'missing_object_detected',
            'non_id_database_change_detected',
            'missing_database_row_detected',
            'missing_database_object_detected',
            'orphan_storage_object_detected',
            'empty_storage_with_database_reference_rejected',
        ] as $control) {
            if (($controls[$control] ?? null) !== true) {
                $errors[] = "negative_control_{$control}_failed";
            }
        }

        $result = $this->section($evidence, 'result');
        if (($result['status'] ?? null) !== 'passed') {
            $errors[] = 'drill_result_not_passed';
        }
        if (($result['errors'] ?? null) !== []) {
            $errors[] = 'drill_reported_errors';
        }

        $external = $this->section($evidence, 'external_gates');
        foreach (['independent_verification', 'dpo_approval', 'legal_approval', 'production_restore'] as $gate) {
            if (($external[$gate] ?? null) !== 'open') {
                $errors[] = "external_gate_{$gate}_must_remain_open";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $timing
     * @return list<string>
     */
    private function timingErrors(array $timing): array
    {
        $errors = [];
        $fields = [
            'operation_started_at',
            'started_at',
            'backup_completed_at',
            'database_backup_created_at',
            'database_last_restored_record_at',
            'database_restored_at',
            'object_storage_backup_created_at',
            'object_storage_last_restored_record_at',
            'object_storage_restored_at',
            'completed_at',
        ];
        $dates = [];

        foreach ($fields as $field) {
            $date = $this->date($timing[$field] ?? null);
            if ($date === null) {
                $errors[] = "timing_{$field}_invalid";
            }
            $dates[$field] = $date;
        }

        if (collect($dates)->every(static fn (?DateTimeImmutable $date): bool => $date !== null)) {
            $ordered = [
                $dates['operation_started_at'],
                $dates['backup_completed_at'],
                $dates['started_at'],
                $dates['database_restored_at'],
                $dates['object_storage_restored_at'],
                $dates['completed_at'],
            ];

            for ($index = 1; $index < count($ordered); $index++) {
                if ($ordered[$index] < $ordered[$index - 1]) {
                    $errors[] = 'timing_clock_rollback_detected';
                    break;
                }
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $maximumAgeDays = max(
                1,
                (int) config('operations.evidence_freshness.backup_restore_days', 90),
            );
            $oldestAllowed = $now->modify("-{$maximumAgeDays} days");
            foreach ($dates as $field => $date) {
                if ($date > $now->modify('+5 minutes')) {
                    $errors[] = "timing_{$field}_future";
                }
                if ($date < $oldestAllowed) {
                    $errors[] = "timing_{$field}_stale";
                }
            }

            $databaseRto = $dates['database_restored_at']->getTimestamp()
                - $dates['started_at']->getTimestamp();
            $objectRto = $dates['object_storage_restored_at']->getTimestamp()
                - $dates['started_at']->getTimestamp();
            $databaseRpo = $dates['started_at']->getTimestamp()
                - $dates['database_last_restored_record_at']->getTimestamp();
            $objectRpo = $dates['started_at']->getTimestamp()
                - $dates['object_storage_last_restored_record_at']->getTimestamp();

            foreach (['database', 'object_storage'] as $system) {
                $lastRecord = $dates["{$system}_last_restored_record_at"];
                $backupCreated = $dates["{$system}_backup_created_at"];
                $restored = $dates["{$system}_restored_at"];
                if ($lastRecord > $backupCreated) {
                    $errors[] = "{$system}_record_after_backup";
                }
                if ($backupCreated > $dates['backup_completed_at']) {
                    $errors[] = "{$system}_backup_after_backup_completion";
                }
                if ($backupCreated > $dates['started_at']) {
                    $errors[] = "{$system}_backup_after_drill_start";
                }
                if ($dates['started_at'] > $restored) {
                    $errors[] = "{$system}_restore_before_drill";
                }
                if ($restored > $dates['completed_at']) {
                    $errors[] = "{$system}_restore_after_completion";
                }
            }
            if (($timing['database_rto_achieved_seconds'] ?? null) !== $databaseRto) {
                $errors[] = 'database_rto_measurement_mismatch';
            }
            if (($timing['object_storage_rto_achieved_seconds'] ?? null) !== $objectRto) {
                $errors[] = 'object_storage_rto_measurement_mismatch';
            }
            if (($timing['database_rpo_achieved_seconds'] ?? null) !== $databaseRpo) {
                $errors[] = 'database_rpo_measurement_mismatch';
            }
            if (($timing['object_storage_rpo_achieved_seconds'] ?? null) !== $objectRpo) {
                $errors[] = 'object_storage_rpo_measurement_mismatch';
            }
        }

        foreach (['database', 'object_storage'] as $system) {
            $rpoTarget = $timing["{$system}_rpo_target_seconds"] ?? null;
            $rpoAchieved = $timing["{$system}_rpo_achieved_seconds"] ?? null;
            $rtoTarget = $timing["{$system}_rto_target_seconds"] ?? null;
            $rtoAchieved = $timing["{$system}_rto_achieved_seconds"] ?? null;

            if (! $this->positiveInteger($rpoTarget)) {
                $errors[] = "{$system}_rpo_target_invalid";
            }
            if (! is_int($rpoAchieved) || $rpoAchieved < 0) {
                $errors[] = "{$system}_rpo_achieved_invalid";
            } elseif (is_int($rpoTarget) && $rpoAchieved > $rpoTarget) {
                $errors[] = "{$system}_rpo_target_missed";
            }
            if (! $this->positiveInteger($rtoTarget)) {
                $errors[] = "{$system}_rto_target_invalid";
            }
            if (! is_int($rtoAchieved) || $rtoAchieved < 0) {
                $errors[] = "{$system}_rto_achieved_invalid";
            } elseif (is_int($rtoTarget) && $rtoAchieved > $rtoTarget) {
                $errors[] = "{$system}_rto_target_missed";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function artifactErrors(array $artifact, string $directory, string $name): array
    {
        $errors = [];
        $filename = $artifact['file'] ?? null;
        $expectedHash = $artifact['sha256'] ?? null;
        $expectedSize = $artifact['size_bytes'] ?? null;

        if (
            ! is_string($filename)
            || basename($filename) !== $filename
            || preg_match('/\A[a-zA-Z0-9._-]+\.enc\z/', $filename) !== 1
        ) {
            return ["{$name}_artifact_name_invalid"];
        }

        $artifactPath = $directory.DIRECTORY_SEPARATOR.$filename;
        if (! is_file($artifactPath) || is_link($artifactPath) || ! is_readable($artifactPath)) {
            return ["{$name}_artifact_missing_or_unsafe"];
        }

        if (! is_string($expectedHash) || preg_match('/\A[0-9a-f]{64}\z/', $expectedHash) !== 1) {
            $errors[] = "{$name}_artifact_hash_invalid";
        } elseif (! hash_equals($expectedHash, (string) hash_file('sha256', $artifactPath))) {
            $errors[] = "{$name}_artifact_tampered";
        }

        if (! $this->positiveInteger($expectedSize) || filesize($artifactPath) !== $expectedSize) {
            $errors[] = "{$name}_artifact_size_mismatch";
        }
        if (($artifact['encrypted'] ?? null) !== true) {
            $errors[] = "{$name}_artifact_not_encrypted";
        }
        if (($artifact['ciphertext_format'] ?? null) !== 'openssl-salted-v1') {
            $errors[] = "{$name}_ciphertext_format_invalid";
        } else {
            $stream = fopen($artifactPath, 'rb');
            $header = is_resource($stream) ? fread($stream, 8) : false;
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($header !== 'Salted__') {
                $errors[] = "{$name}_ciphertext_header_invalid";
            }
        }
        if (($artifact['mac_verified_before_decryption'] ?? null) !== true) {
            $errors[] = "{$name}_artifact_mac_not_verified";
        }

        $macFilename = $artifact['hmac_file'] ?? null;
        if (
            ! is_string($macFilename)
            || $macFilename !== $filename.'.hmac'
            || basename($macFilename) !== $macFilename
        ) {
            $errors[] = "{$name}_artifact_hmac_name_invalid";

            return $errors;
        }
        $macPath = $directory.DIRECTORY_SEPARATOR.$macFilename;
        if (! is_file($macPath) || is_link($macPath) || ! is_readable($macPath)) {
            $errors[] = "{$name}_artifact_hmac_missing_or_unsafe";

            return $errors;
        }
        $mac = file_get_contents($macPath);
        if (! is_string($mac) || preg_match('/\A[0-9a-f]{64}\n?\z/', $mac) !== 1) {
            $errors[] = "{$name}_artifact_hmac_invalid";
        }
        $expectedMacFileHash = $artifact['hmac_sha256'] ?? null;
        if (
            ! is_string($expectedMacFileHash)
            || preg_match('/\A[0-9a-f]{64}\z/', $expectedMacFileHash) !== 1
            || ! hash_equals($expectedMacFileHash, (string) hash_file('sha256', $macPath))
        ) {
            $errors[] = "{$name}_artifact_hmac_sidecar_tampered";
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function evidenceSidecarErrors(string $path): array
    {
        $sidecar = $path.'.sha256';
        if (! is_file($sidecar) || is_link($sidecar) || ! is_readable($sidecar)) {
            return ['evidence_checksum_missing_or_unsafe'];
        }

        $contents = file_get_contents($sidecar);
        if (
            ! is_string($contents)
            || preg_match(
                '/\A([0-9a-f]{64}) {2}'.preg_quote(basename($path), '/').'\n?\z/',
                $contents,
                $matches,
            ) !== 1
        ) {
            return ['evidence_checksum_invalid'];
        }
        if (
            ! is_file($path)
            || is_link($path)
            || ! hash_equals($matches[1], (string) hash_file('sha256', $path))
        ) {
            return ['evidence_checksum_mismatch'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $objects
     * @return list<string>
     */
    private function objectStorageErrors(array $objects): array
    {
        $errors = [];
        $sourceCount = $objects['source_count'] ?? null;
        $restoredCount = $objects['restored_count'] ?? null;
        $sourceBytes = $objects['source_bytes'] ?? null;
        $restoredBytes = $objects['restored_bytes'] ?? null;
        $sourceManifest = $objects['source_manifest_sha256'] ?? null;
        $restoredManifest = $objects['restored_manifest_sha256'] ?? null;

        if (! $this->positiveInteger($sourceCount) || $sourceCount !== $restoredCount) {
            $errors[] = 'object_count_mismatch';
        }
        if (! is_int($sourceBytes) || $sourceBytes < 0 || $sourceBytes !== $restoredBytes) {
            $errors[] = 'object_size_mismatch';
        }
        if (
            ! is_string($sourceManifest)
            || preg_match('/\A[0-9a-f]{64}\z/', $sourceManifest) !== 1
            || ! is_string($restoredManifest)
            || ! hash_equals($sourceManifest, $restoredManifest)
        ) {
            $errors[] = 'object_manifest_mismatch';
        }
        if (($objects['missing_objects'] ?? null) !== 0) {
            $errors[] = 'objects_missing';
        }
        if (($objects['unexpected_objects'] ?? null) !== 0) {
            $errors[] = 'objects_unexpected';
        }
        if (($objects['drill_canary_included'] ?? null) !== true) {
            $errors[] = 'object_drill_canary_missing';
        }

        $applicationObjectCount = $objects['source_application_object_count'] ?? null;
        $databaseReferenceCount = $objects['database_object_reference_count'] ?? null;
        if (
            ! is_int($applicationObjectCount)
            || $applicationObjectCount < 0
            || ! is_int($databaseReferenceCount)
            || $databaseReferenceCount < 0
            || $applicationObjectCount !== $databaseReferenceCount
        ) {
            $errors[] = 'database_object_reference_count_mismatch';
        }
        $databaseReferenceManifest = $objects['database_object_reference_manifest_sha256'] ?? null;
        $applicationObjectManifest = $objects['application_object_key_manifest_sha256'] ?? null;
        if (
            ! is_string($databaseReferenceManifest)
            || preg_match('/\A[0-9a-f]{64}\z/', $databaseReferenceManifest) !== 1
            || ! is_string($applicationObjectManifest)
            || ! hash_equals($databaseReferenceManifest, $applicationObjectManifest)
        ) {
            $errors[] = 'database_object_reference_manifest_mismatch';
        }
        if (($objects['missing_database_referenced_objects'] ?? null) !== 0) {
            $errors[] = 'database_referenced_objects_missing';
        }
        if (($objects['orphan_application_objects'] ?? null) !== 0) {
            $errors[] = 'orphan_application_objects_present';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function section(array $value, string $key): array
    {
        $section = $value[$key] ?? null;

        return is_array($section) ? $section : [];
    }

    private function positiveInteger(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $timezone = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, $timezone);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d\TH:i:s\Z') === $value
            ? $date
            : null;
    }
}
