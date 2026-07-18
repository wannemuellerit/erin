<?php

namespace App\Services\Operations;

use DateTimeImmutable;
use DateTimeZone;

final class LaunchEvidenceValidator
{
    public function __construct(
        private readonly GovernanceAttestationVerifier $attestationVerifier,
    ) {}

    /**
     * @return list<array{
     *     id: string,
     *     status: 'pass'|'warn'|'fail',
     *     message: string,
     *     errors: list<string>
     * }>
     */
    public function checks(): array
    {
        return $this->checksWithAttestationPin(null);
    }

    /**
     * Runs the same rules against a disposable trust-root pin exclusively for
     * unit tests and the explicitly synthetic adversarial control model.
     *
     * @return list<array{
     *     id: string,
     *     status: 'pass'|'warn'|'fail',
     *     message: string,
     *     errors: list<string>
     * }>
     */
    public function checksAgainstSyntheticTestPin(string $fingerprint): array
    {
        return $this->checksWithAttestationPin($fingerprint);
    }

    /**
     * @return list<array{
     *     id: string,
     *     status: 'pass'|'warn'|'fail',
     *     message: string,
     *     errors: list<string>
     * }>
     */
    private function checksWithAttestationPin(?string $syntheticTestFingerprint): array
    {
        $evidence = config('operations.launch_evidence', []);
        $evidence = is_array($evidence) ? $evidence : [];
        $failureStatus = config('app.env') === 'production' ? 'fail' : 'warn';

        $release = $this->section($evidence, 'release');
        $backup = $this->section($evidence, 'backup_restore');
        $security = $this->section($evidence, 'security_review');
        $dpo = $this->section($evidence, 'dpo_approval');
        $legal = $this->section($evidence, 'legal_approval');
        $pilot = $this->section($evidence, 'pilot');

        $releaseId = $this->validReleaseId($release['id'] ?? null);
        $releaseCommit = $this->validCommitSha($release['commit_sha'] ?? null);
        $buildCommit = $this->validCommitSha(config('operations.build.sha'));
        $preparedBy = $this->identityEmail($release['prepared_by'] ?? null);

        $releaseErrors = array_values(array_filter([
            $releaseId !== null ? null : 'release_id_invalid',
            $releaseCommit !== null ? null : 'commit_sha_invalid',
            $buildCommit !== null ? null : 'embedded_build_sha_invalid',
            $buildCommit === null || $releaseCommit === null || $buildCommit === $releaseCommit
                ? null
                : 'evidence_build_sha_mismatch',
            $preparedBy !== null ? null : 'prepared_by_invalid',
        ]));

        $backupErrors = $this->commonEvidenceErrors(
            $backup,
            'verified_by',
            'verified_at',
            $releaseId,
            (int) config('operations.evidence_freshness.backup_restore_days', 90),
        );
        $backupErrors = [
            ...$backupErrors,
            ...$this->backupMetricErrors($backup),
            ...$this->backupMeasurementErrors($backup),
            ...$this->orderedEvidenceDates(
                $backup,
                'drill_started_at',
                'drill_completed_at',
                'verified_at',
                'backup_clock_rollback',
            ),
            $this->validEvidenceDate(
                $backup['drill_completed_at'] ?? null,
                (int) config('operations.evidence_freshness.backup_restore_days', 90),
            )
                ? null
                : 'drill_completed_at_invalid_or_stale',
            ($backup['scope'] ?? null) === 'production'
                ? null
                : 'backup_scope_not_production',
            ($backup['production_gate_eligible'] ?? null) === true
                ? null
                : 'backup_not_production_gate_eligible',
            ($backup['independently_verified'] ?? null) === true
                ? null
                : 'backup_not_independently_verified',
            ($backup['encrypted_backup_verified'] ?? null) === true
                ? null
                : 'encrypted_backup_not_verified',
            ($backup['isolated_restore_verified'] ?? null) === true
                ? null
                : 'isolated_restore_not_verified',
        ];

        $securityErrors = $this->commonEvidenceErrors(
            $security,
            'reviewed_by',
            'reviewed_at',
            $releaseId,
            (int) config('operations.evidence_freshness.security_review_days', 30),
        );
        $securityCommit = $this->validCommitSha($security['commit_sha'] ?? null);
        $securityErrors = [
            ...$securityErrors,
            $securityCommit !== null && $securityCommit === $releaseCommit
                ? null
                : 'review_commit_mismatch',
            $this->validReference($security['automated_evidence_reference'] ?? null)
                ? null
                : 'automated_evidence_reference_invalid',
            $this->nonNegativeInteger($security['open_critical_findings'] ?? null) === 0
                ? null
                : 'open_critical_findings',
            $this->nonNegativeInteger($security['open_high_findings'] ?? null) === 0
                ? null
                : 'open_high_findings',
            ($security['independent_review_verified'] ?? null) === true
                ? null
                : 'independent_security_review_not_verified',
            ($security['penetration_test_verified'] ?? null) === true
                ? null
                : 'penetration_test_not_verified',
        ];

        $dpoErrors = [
            ...$this->commonEvidenceErrors(
                $dpo,
                'approved_by',
                'approved_at',
                $releaseId,
                (int) config('operations.evidence_freshness.dpo_approval_days', 365),
            ),
            ($dpo['status'] ?? null) === 'approved' ? null : 'approval_status_invalid',
            ($dpo['authority_verified'] ?? null) === true
                ? null
                : 'dpo_authority_not_verified',
        ];

        $legalErrors = [
            ...$this->commonEvidenceErrors(
                $legal,
                'approved_by',
                'approved_at',
                $releaseId,
                (int) config('operations.evidence_freshness.legal_approval_days', 365),
            ),
            ($legal['status'] ?? null) === 'approved' ? null : 'approval_status_invalid',
            ($legal['authority_verified'] ?? null) === true
                ? null
                : 'legal_authority_not_verified',
        ];

        $pilotErrors = $this->commonEvidenceErrors(
            $pilot,
            'decision_by',
            'decision_at',
            $releaseId,
            (int) config('operations.evidence_freshness.pilot_decision_days', 90),
        );
        $pilotErrors = [
            ...$pilotErrors,
            $this->identityEmail($pilot['owner'] ?? null) !== null ? null : 'owner_invalid',
            $this->identityEmail($pilot['deputy'] ?? null) !== null ? null : 'deputy_invalid',
            $this->validReference($pilot['plan_reference'] ?? null) ? null : 'plan_reference_invalid',
            $this->validReference($pilot['acceptance_reference'] ?? null)
                ? null
                : 'acceptance_reference_invalid',
            $this->validReference($pilot['rollback_reference'] ?? null)
                ? null
                : 'rollback_reference_invalid',
            ...$this->orderedEvidenceDates(
                $pilot,
                'started_at',
                'decision_at',
                null,
                'pilot_clock_rollback',
            ),
            ($pilot['synthetic'] ?? null) === false ? null : 'synthetic_pilot_not_eligible',
            ($pilot['participant_consent_verified'] ?? null) === true
                ? null
                : 'pilot_participant_consent_not_verified',
            ($pilot['stop_criteria_tested'] ?? null) === true
                ? null
                : 'pilot_stop_criteria_not_tested',
            ($pilot['rollback_tested'] ?? null) === true
                ? null
                : 'pilot_rollback_not_tested',
            ($pilot['status'] ?? null) === 'approved' ? null : 'pilot_status_invalid',
        ];
        $attestationErrors = $syntheticTestFingerprint === null
            ? $this->attestationVerifier->errors($evidence)
            : $this->attestationVerifier->errorsAgainstSyntheticTestPin(
                $evidence,
                $syntheticTestFingerprint,
            );

        $backupErrors = [
            ...$backupErrors,
            ...$this->independenceErrors(
                $preparedBy,
                $this->identityEmail($backup['verified_by'] ?? null),
                'backup_self_approval',
            ),
        ];
        $securityErrors = [
            ...$securityErrors,
            ...$this->independenceErrors(
                $preparedBy,
                $this->identityEmail($security['reviewed_by'] ?? null),
                'security_self_approval',
            ),
        ];
        $dpoErrors = [
            ...$dpoErrors,
            ...$this->independenceErrors(
                $preparedBy,
                $this->identityEmail($dpo['approved_by'] ?? null),
                'dpo_self_approval',
            ),
        ];
        $legalErrors = [
            ...$legalErrors,
            ...$this->independenceErrors(
                $preparedBy,
                $this->identityEmail($legal['approved_by'] ?? null),
                'legal_self_approval',
            ),
        ];

        $dpoApprover = $this->identityEmail($dpo['approved_by'] ?? null);
        $legalApprover = $this->identityEmail($legal['approved_by'] ?? null);
        if ($dpoApprover !== null && $dpoApprover === $legalApprover) {
            $dpoErrors[] = 'dpo_legal_roles_not_separated';
            $legalErrors[] = 'dpo_legal_roles_not_separated';
        }

        $pilotOwner = $this->identityEmail($pilot['owner'] ?? null);
        $pilotDeputy = $this->identityEmail($pilot['deputy'] ?? null);
        $pilotDecisionBy = $this->identityEmail($pilot['decision_by'] ?? null);
        $pilotActors = array_filter([$pilotOwner, $pilotDeputy, $pilotDecisionBy]);
        if (count($pilotActors) !== count(array_unique($pilotActors))) {
            $pilotErrors[] = 'pilot_roles_not_separated';
        }
        $pilotErrors = [
            ...$pilotErrors,
            ...$this->independenceErrors(
                $preparedBy,
                $pilotDecisionBy,
                'pilot_self_approval',
            ),
        ];

        return [
            $this->result(
                'evidence.release',
                $releaseErrors,
                'Release-ID, Evidenz, eingebauter Commit und vorbereitende Person sind eindeutig gebunden.',
                $failureStatus,
            ),
            $this->result(
                'backup.restore_drill',
                $backupErrors,
                'Verschlüsselter Datenbank- und Objekt-Restore ist gemessen und unabhängig verifiziert.',
                $failureStatus,
            ),
            $this->result(
                'security.review',
                $securityErrors,
                'Die Sicherheitsprüfung ist aktuell, commitgebunden und ohne offene hohe Befunde freigegeben.',
                $failureStatus,
            ),
            $this->result(
                'dpo.approval',
                $dpoErrors,
                'Die Datenschutzfreigabe ist mit Person, Datum, Scope und Evidenz belegt.',
                $failureStatus,
            ),
            $this->result(
                'legal.approval',
                $legalErrors,
                'Die rechtliche Freigabe ist mit Person, Datum, Scope und Evidenz belegt.',
                $failureStatus,
            ),
            $this->result(
                'pilot.approval',
                $pilotErrors,
                'Pilotverantwortung, Abnahmekriterien, Rollback und Go-Entscheidung sind belegt.',
                $failureStatus,
            ),
            $this->result(
                'evidence.attestation',
                $attestationErrors,
                'Die Governance-Evidenz ist Ed25519-signiert an Release, Commit und Evidenzdigest gebunden.',
                $failureStatus,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function section(array $evidence, string $key): array
    {
        $section = $evidence[$key] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return list<string|null>
     */
    private function commonEvidenceErrors(
        array $section,
        string $identityField,
        string $dateField,
        ?string $releaseId,
        int $freshnessDays,
    ): array {
        return [
            $this->validReference($section['reference'] ?? null) ? null : 'reference_invalid',
            $this->identityEmail($section[$identityField] ?? null) !== null
                ? null
                : "{$identityField}_invalid",
            $this->validEvidenceDate($section[$dateField] ?? null, $freshnessDays)
                ? null
                : "{$dateField}_invalid_or_stale",
            is_string($section['release_id'] ?? null)
                && $releaseId !== null
                && hash_equals($releaseId, $section['release_id'])
                    ? null
                    : 'release_scope_mismatch',
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return list<string|null>
     */
    private function backupMetricErrors(array $section): array
    {
        $errors = [];

        foreach (['database', 'object_storage'] as $system) {
            foreach (['rpo', 'rto'] as $objective) {
                $target = $this->nonNegativeInteger(
                    $section["{$system}_{$objective}_target_minutes"] ?? null,
                );
                $achieved = $this->nonNegativeInteger(
                    $section["{$system}_{$objective}_achieved_minutes"] ?? null,
                );

                if ($target === null || $target === 0) {
                    $errors[] = "{$system}_{$objective}_target_invalid";
                }
                if ($achieved === null) {
                    $errors[] = "{$system}_{$objective}_achieved_invalid";
                } elseif ($target !== null && $target > 0 && $achieved > $target) {
                    $errors[] = "{$system}_{$objective}_target_missed";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $section
     * @return list<string>
     */
    private function backupMeasurementErrors(array $section): array
    {
        $errors = [];
        $drillStartedAt = $this->evidenceDate($section['drill_started_at'] ?? null);
        $drillCompletedAt = $this->evidenceDate($section['drill_completed_at'] ?? null);
        $freshnessDays = (int) config(
            'operations.evidence_freshness.backup_restore_days',
            90,
        );

        if (! $this->validEvidenceDate($section['drill_started_at'] ?? null, $freshnessDays)) {
            $errors[] = 'drill_started_at_invalid_or_stale';
        }

        foreach (['database', 'object_storage'] as $system) {
            $backupCreatedAt = $this->evidenceDate($section["{$system}_backup_created_at"] ?? null);
            $lastRecordAt = $this->evidenceDate(
                $section["{$system}_last_restored_record_at"] ?? null,
            );
            $restoredAt = $this->evidenceDate($section["{$system}_restored_at"] ?? null);

            if ($backupCreatedAt === null) {
                $errors[] = "{$system}_backup_created_at_invalid";
            }
            if ($lastRecordAt === null) {
                $errors[] = "{$system}_last_restored_record_at_invalid";
            }
            if ($restoredAt === null) {
                $errors[] = "{$system}_restored_at_invalid";
            }
            if (! $this->validEvidenceDate(
                $section["{$system}_backup_created_at"] ?? null,
                $freshnessDays,
            )) {
                $errors[] = "{$system}_backup_created_at_future_or_stale";
            }
            if (! $this->validEvidenceDate(
                $section["{$system}_last_restored_record_at"] ?? null,
                $freshnessDays,
            )) {
                $errors[] = "{$system}_last_restored_record_at_future_or_stale";
            }
            if (! $this->validEvidenceDate(
                $section["{$system}_restored_at"] ?? null,
                $freshnessDays,
            )) {
                $errors[] = "{$system}_restored_at_future_or_stale";
            }

            if (
                $backupCreatedAt !== null
                && $lastRecordAt !== null
                && $lastRecordAt > $backupCreatedAt
            ) {
                $errors[] = "{$system}_record_after_backup";
            }
            if (
                $backupCreatedAt !== null
                && $drillStartedAt !== null
                && $backupCreatedAt > $drillStartedAt
            ) {
                $errors[] = "{$system}_backup_after_drill_start";
            }
            if (
                $drillStartedAt !== null
                && $restoredAt !== null
                && $restoredAt < $drillStartedAt
            ) {
                $errors[] = "{$system}_restore_before_drill";
            }
            if (
                $drillCompletedAt !== null
                && $restoredAt !== null
                && $restoredAt > $drillCompletedAt
            ) {
                $errors[] = "{$system}_restore_after_drill";
            }

            $declaredRpo = $this->nonNegativeInteger(
                $section["{$system}_rpo_achieved_minutes"] ?? null,
            );
            $measuredRpo = $this->elapsedMinutes($lastRecordAt, $drillStartedAt);
            if (
                $declaredRpo !== null
                && $measuredRpo !== null
                && $declaredRpo !== $measuredRpo
            ) {
                $errors[] = "{$system}_rpo_measurement_mismatch";
            }

            $declaredRto = $this->nonNegativeInteger(
                $section["{$system}_rto_achieved_minutes"] ?? null,
            );
            $measuredRto = $this->elapsedMinutes($drillStartedAt, $restoredAt);
            if (
                $declaredRto !== null
                && $measuredRto !== null
                && $declaredRto !== $measuredRto
            ) {
                $errors[] = "{$system}_rto_measurement_mismatch";
            }
        }

        return $errors;
    }

    private function elapsedMinutes(
        ?DateTimeImmutable $start,
        ?DateTimeImmutable $end,
    ): ?int {
        if ($start === null || $end === null || $end < $start) {
            return null;
        }

        $seconds = $end->getTimestamp() - $start->getTimestamp();

        return intdiv($seconds + 59, 60);
    }

    /**
     * @return list<string>
     */
    private function independenceErrors(
        ?string $preparedBy,
        ?string $approver,
        string $error,
    ): array {
        return $preparedBy !== null && $approver !== null && hash_equals($preparedBy, $approver)
            ? [$error]
            : [];
    }

    private function validReleaseId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{5,79}\z/', $value) === 1
            && ! $this->isPlaceholder($value)
                ? $value
                : null;
    }

    private function validCommitSha(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return preg_match('/\A[0-9a-f]{40}\z/', $value) === 1
            && preg_match('/\A([0-9a-f])\1{39}\z/', $value) !== 1
                ? $value
                : null;
    }

    private function identityEmail(mixed $value): ?string
    {
        if (
            ! is_string($value)
            || preg_match('/\A(.+?)\s*<([^<>]+)>\z/u', trim($value), $matches) !== 1
        ) {
            return null;
        }

        $name = trim($matches[1]);
        $email = strtolower(trim($matches[2]));
        $host = strrchr($email, '@');
        $host = is_string($host) ? substr($host, 1) : null;

        if (
            preg_match('/\A[\p{L}][\p{L}\p{M}.\'-]+(?:\s+[\p{L}][\p{L}\p{M}.\'-]+)+\z/u', $name) !== 1
            || $this->isPlaceholder($name)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $host === null
            || $this->blockedHost($host)
        ) {
            return null;
        }

        return $email;
    }

    private function validReference(mixed $value): bool
    {
        if (! is_string($value) || $this->isPlaceholder($value)) {
            return false;
        }

        $parts = parse_url(trim($value));
        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $this->blockedHost($parts['host'])
            || filter_var($parts['host'], FILTER_VALIDATE_IP) !== false
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! is_string($parts['path'] ?? null)
            || trim($parts['path'], '/') === ''
        ) {
            return false;
        }

        return true;
    }

    private function validEvidenceDate(mixed $value, int $freshnessDays): bool
    {
        $date = $this->evidenceDate($value);
        if ($date === null || $freshnessDays < 1) {
            return false;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $date <= $now->modify('+5 minutes')
            && $date >= $now->modify("-{$freshnessDays} days");
    }

    /**
     * @param  array<string, mixed>  $section
     * @return list<string>
     */
    private function orderedEvidenceDates(
        array $section,
        string $startField,
        string $endField,
        ?string $finalField,
        string $rollbackError,
    ): array {
        $start = $this->evidenceDate($section[$startField] ?? null);
        $end = $this->evidenceDate($section[$endField] ?? null);
        $final = $finalField !== null
            ? $this->evidenceDate($section[$finalField] ?? null)
            : null;
        $errors = [];

        if ($start === null) {
            $errors[] = "{$startField}_invalid";
        }
        if ($end === null) {
            $errors[] = "{$endField}_invalid";
        }
        if ($start !== null && $end !== null && $end < $start) {
            $errors[] = $rollbackError;
        }
        if ($finalField !== null && $final === null) {
            $errors[] = "{$finalField}_invalid";
        }
        if ($end !== null && $final !== null && $final < $end) {
            $errors[] = $rollbackError;
        }

        return $errors;
    }

    private function evidenceDate(mixed $value): ?DateTimeImmutable
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

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A\d+\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function blockedHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.invalid')
            || str_ends_with($host, '.example')
            || in_array($host, ['example.com', 'example.net', 'example.org'], true);
    }

    private function isPlaceholder(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return $normalized === ''
            || preg_match('/\b(?:tbd|todo|fixme|changeme|placeholder|pending|unknown|dummy|sample|example|draft|lorem|ipsum)\b/i', $value) === 1
            || in_array($normalized, ['n/a', 'na', 'none', 'null', 'xxx', 'test', 'not-set', 'unset'], true);
    }

    /**
     * @param  list<string|null>  $errors
     * @param  'warn'|'fail'  $failureStatus
     * @return array{
     *     id: string,
     *     status: 'pass'|'warn'|'fail',
     *     message: string,
     *     errors: list<string>
     * }
     */
    private function result(
        string $id,
        array $errors,
        string $success,
        string $failureStatus,
    ): array {
        $errors = array_values(array_unique(array_filter(
            $errors,
            static fn (mixed $error): bool => is_string($error) && $error !== '',
        )));

        return [
            'id' => $id,
            'status' => $errors === [] ? 'pass' : $failureStatus,
            'message' => $errors === []
                ? $success
                : 'Evidenz ist unvollständig, veraltet, nicht releasegebunden oder verletzt die Rollentrennung.',
            'errors' => $errors,
        ];
    }
}
