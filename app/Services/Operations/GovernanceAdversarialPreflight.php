<?php

namespace App\Services\Operations;

use Closure;
use Illuminate\Support\Str;
use RuntimeException;

final class GovernanceAdversarialPreflight
{
    public function __construct(
        private readonly LaunchEvidenceValidator $validator,
        private readonly GovernanceAttestationPayload $attestationPayload,
    ) {}

    /**
     * @return array{
     *     schema_version: int,
     *     status: 'passed'|'failed',
     *     classification: string,
     *     baseline: array{status: 'passed'|'failed', errors: list<string>},
     *     summary: array{attacks: int, detected: int, escaped: int},
     *     attacks: list<array{id: string, status: 'detected'|'escaped', expected_errors: list<string>, observed_errors: list<string>, error_delta: list<string>}>
     * }
     */
    public function run(): array
    {
        $originalEvidence = config('operations.launch_evidence');
        $originalBuildSha = config('operations.build.sha');
        $originalEnvironment = config('app.env');
        $originalAttestation = config('operations.governance_attestation');
        $attacks = [];
        $baselineErrors = [];
        $directory = $this->temporaryDirectory();
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);

        try {
            $baseline = $this->validSyntheticEvidence();
            $this->configureSyntheticEvidence(
                $baseline,
                $directory,
                $secretKey,
                $publicKey,
            );
            $baselineErrors = $this->observedErrors();

            foreach ($this->attackDefinitions() as $attack) {
                $evidence = $this->validSyntheticEvidence();
                ($attack['mutate'])($evidence);
                $this->configureSyntheticEvidence(
                    $evidence,
                    $directory,
                    $secretKey,
                    $publicKey,
                );
                $observed = $this->observedErrors();
                $delta = array_values(array_diff($observed, $baselineErrors));
                $detected = $baselineErrors === []
                    && collect($attack['expected'])->every(
                        static fn (string $error): bool => in_array($error, $delta, true),
                    );

                $attacks[] = [
                    'id' => $attack['id'],
                    'status' => $detected ? 'detected' : 'escaped',
                    'expected_errors' => $attack['expected'],
                    'observed_errors' => $observed,
                    'error_delta' => $delta,
                ];
            }
        } finally {
            config([
                'app.env' => $originalEnvironment,
                'operations.build.sha' => $originalBuildSha,
                'operations.launch_evidence' => $originalEvidence,
                'operations.governance_attestation' => $originalAttestation,
            ]);
            $this->removeTemporaryDirectory($directory);
        }

        $detected = collect($attacks)->where('status', 'detected')->count();
        $escaped = count($attacks) - $detected;

        return [
            'schema_version' => 1,
            'status' => $escaped === 0 && $baselineErrors === [] ? 'passed' : 'failed',
            'classification' => 'SYNTHETIC_ADVERSARIAL_PREFLIGHT',
            'baseline' => [
                'status' => $baselineErrors === [] ? 'passed' : 'failed',
                'errors' => $baselineErrors,
            ],
            'summary' => [
                'attacks' => count($attacks),
                'detected' => $detected,
                'escaped' => $escaped,
            ],
            'attacks' => $attacks,
        ];
    }

    /**
     * @return list<array{id: string, mutate: Closure(array<string, mixed>&): void, expected: list<string>}>
     */
    private function attackDefinitions(): array
    {
        return [
            [
                'id' => 'placeholder-reference',
                'mutate' => static function (array &$evidence): void {
                    $evidence['dpo_approval']['reference'] = 'https://evidence.wannemueller.dev/changeme';
                },
                'expected' => ['reference_invalid'],
            ],
            [
                'id' => 'release-self-approval',
                'mutate' => static function (array &$evidence): void {
                    $evidence['security_review']['reviewed_by'] = $evidence['release']['prepared_by'];
                },
                'expected' => ['security_self_approval'],
            ],
            [
                'id' => 'stale-security-review',
                'mutate' => static function (array &$evidence): void {
                    $evidence['security_review']['reviewed_at'] = now()
                        ->utc()
                        ->subDays(31)
                        ->format('Y-m-d\TH:i:s\Z');
                },
                'expected' => ['reviewed_at_invalid_or_stale'],
            ],
            [
                'id' => 'future-pilot-decision',
                'mutate' => static function (array &$evidence): void {
                    $evidence['pilot']['decision_at'] = now()
                        ->utc()
                        ->addMinutes(10)
                        ->format('Y-m-d\TH:i:s\Z');
                },
                'expected' => ['decision_at_invalid_or_stale'],
            ],
            [
                'id' => 'release-scope-drift',
                'mutate' => static function (array &$evidence): void {
                    $evidence['legal_approval']['release_id'] = 'erin-other-release';
                },
                'expected' => ['release_scope_mismatch'],
            ],
            [
                'id' => 'weak-repeated-commit',
                'mutate' => static function (array &$evidence): void {
                    $evidence['release']['commit_sha'] = str_repeat('0', 40);
                },
                'expected' => ['commit_sha_invalid'],
            ],
            [
                'id' => 'local-backup-as-production-evidence',
                'mutate' => static function (array &$evidence): void {
                    $evidence['backup_restore']['scope'] = 'local';
                    $evidence['backup_restore']['production_gate_eligible'] = false;
                    $evidence['backup_restore']['independently_verified'] = false;
                },
                'expected' => [
                    'backup_scope_not_production',
                    'backup_not_production_gate_eligible',
                    'backup_not_independently_verified',
                ],
            ],
            [
                'id' => 'restore-target-missed',
                'mutate' => static function (array &$evidence): void {
                    $evidence['backup_restore']['database_rto_achieved_minutes'] = '121';
                },
                'expected' => ['database_rto_target_missed'],
            ],
            [
                'id' => 'restore-clock-rollback',
                'mutate' => static function (array &$evidence): void {
                    $evidence['backup_restore']['drill_started_at'] = now()
                        ->utc()
                        ->format('Y-m-d\TH:i:s\Z');
                    $evidence['backup_restore']['drill_completed_at'] = now()
                        ->utc()
                        ->subMinute()
                        ->format('Y-m-d\TH:i:s\Z');
                },
                'expected' => ['backup_clock_rollback'],
            ],
            [
                'id' => 'synthetic-pilot-as-real-evidence',
                'mutate' => static function (array &$evidence): void {
                    $evidence['pilot']['synthetic'] = true;
                },
                'expected' => ['synthetic_pilot_not_eligible'],
            ],
            [
                'id' => 'pilot-role-collision',
                'mutate' => static function (array &$evidence): void {
                    $evidence['pilot']['decision_by'] = $evidence['pilot']['owner'];
                },
                'expected' => ['pilot_roles_not_separated'],
            ],
            [
                'id' => 'unverified-formal-authority',
                'mutate' => static function (array &$evidence): void {
                    $evidence['dpo_approval']['authority_verified'] = false;
                    $evidence['legal_approval']['authority_verified'] = false;
                },
                'expected' => [
                    'dpo_authority_not_verified',
                    'legal_authority_not_verified',
                ],
            ],
            [
                'id' => 'missing-independent-security-review',
                'mutate' => static function (array &$evidence): void {
                    $evidence['security_review']['independent_review_verified'] = false;
                    $evidence['security_review']['penetration_test_verified'] = false;
                },
                'expected' => [
                    'independent_security_review_not_verified',
                    'penetration_test_not_verified',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validSyntheticEvidence(): array
    {
        $now = now()->utc();
        $timestamp = $now->copy()->format('Y-m-d\TH:i:s\Z');
        $started = $now->copy()->subMinutes(10)->format('Y-m-d\TH:i:s\Z');
        $completed = $now->copy()->subMinutes(5)->format('Y-m-d\TH:i:s\Z');
        $releaseId = 'erin-adversarial-preflight-2026';
        $commit = $this->commit();

        return [
            'release' => [
                'id' => $releaseId,
                'commit_sha' => $commit,
                'prepared_by' => 'Release Preparation <release.preparation@wannemueller.dev>',
            ],
            'backup_restore' => [
                'reference' => 'https://evidence.wannemueller.dev/restore/adversarial-preflight',
                'verified_by' => 'Restore Verification <restore.verification@wannemueller.dev>',
                'verified_at' => $timestamp,
                'drill_started_at' => $started,
                'drill_completed_at' => $completed,
                'database_backup_created_at' => $now->copy()->subMinutes(12)->format('Y-m-d\TH:i:s\Z'),
                'database_last_restored_record_at' => $now->copy()->subMinutes(13)->format('Y-m-d\TH:i:s\Z'),
                'database_restored_at' => $now->copy()->subMinutes(8)->format('Y-m-d\TH:i:s\Z'),
                'object_storage_backup_created_at' => $now->copy()->subMinutes(12)->format('Y-m-d\TH:i:s\Z'),
                'object_storage_last_restored_record_at' => $now->copy()->subMinutes(13)->format('Y-m-d\TH:i:s\Z'),
                'object_storage_restored_at' => $now->copy()->subMinutes(7)->format('Y-m-d\TH:i:s\Z'),
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
                'reference' => 'https://evidence.wannemueller.dev/security/adversarial-preflight',
                'reviewed_by' => 'Security Reviewer <security.reviewer@wannemueller.dev>',
                'reviewed_at' => $timestamp,
                'release_id' => $releaseId,
                'commit_sha' => $commit,
                'automated_evidence_reference' => 'https://evidence.wannemueller.dev/ci/adversarial-preflight',
                'open_critical_findings' => '0',
                'open_high_findings' => '0',
                'independent_review_verified' => true,
                'penetration_test_verified' => true,
            ],
            'dpo_approval' => [
                'reference' => 'https://evidence.wannemueller.dev/privacy/adversarial-preflight',
                'approved_by' => 'Privacy Officer <privacy.officer@wannemueller.dev>',
                'approved_at' => $timestamp,
                'release_id' => $releaseId,
                'status' => 'approved',
                'authority_verified' => true,
            ],
            'legal_approval' => [
                'reference' => 'https://evidence.wannemueller.dev/legal/adversarial-preflight',
                'approved_by' => 'Legal Counsel <legal.counsel@wannemueller.dev>',
                'approved_at' => $timestamp,
                'release_id' => $releaseId,
                'status' => 'approved',
                'authority_verified' => true,
            ],
            'pilot' => [
                'reference' => 'https://evidence.wannemueller.dev/pilot/adversarial-preflight',
                'owner' => 'Pilot Ownership <pilot.ownership@wannemueller.dev>',
                'deputy' => 'Pilot Deputy <pilot.deputy@wannemueller.dev>',
                'decision_by' => 'Pilot Decision <pilot.decision@wannemueller.dev>',
                'started_at' => $started,
                'decision_at' => $timestamp,
                'release_id' => $releaseId,
                'plan_reference' => 'https://evidence.wannemueller.dev/pilot/plan-adversarial-preflight',
                'acceptance_reference' => 'https://evidence.wannemueller.dev/pilot/acceptance-adversarial-preflight',
                'rollback_reference' => 'https://evidence.wannemueller.dev/pilot/rollback-adversarial-preflight',
                'status' => 'approved',
                'synthetic' => false,
                'participant_consent_verified' => true,
                'stop_criteria_tested' => true,
                'rollback_tested' => true,
            ],
        ];
    }

    private function commit(): string
    {
        return '0123456789abcdef0123456789abcdef01234567';
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  non-empty-string  $secretKey
     * @param  non-empty-string  $publicKey
     */
    private function configureSyntheticEvidence(
        array $evidence,
        string $directory,
        string $secretKey,
        string $publicKey,
    ): void {
        $now = now()->utc();
        $issuer = 'erin-synthetic-adversarial-preflight';
        $keyId = 'synthetic-'.Str::lower(Str::random(12));
        $attestation = [
            'schema_version' => 1,
            'type' => 'erin_launch_governance_attestation',
            'algorithm' => 'Ed25519',
            'issuer' => $issuer,
            'key_id' => $keyId,
            'issued_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->copy()->addHour()->format('Y-m-d\TH:i:s\Z'),
            'release_id' => $evidence['release']['id'] ?? null,
            'commit_sha' => $evidence['release']['commit_sha'] ?? null,
            'evidence_sha256' => $this->attestationPayload->evidenceDigest($evidence),
        ];
        $attestation['signature'] = base64_encode(sodium_crypto_sign_detached(
            $this->attestationPayload->signingMessage($attestation),
            $secretKey,
        ));
        $trustRoot = [
            'schema_version' => 1,
            'issuer' => $issuer,
            'keys' => [[
                'key_id' => $keyId,
                'algorithm' => 'Ed25519',
                'status' => 'active',
                'public_key' => base64_encode($publicKey),
                'not_before' => $now->copy()->subDay()->format('Y-m-d\TH:i:s\Z'),
                'not_after' => $now->copy()->addDay()->format('Y-m-d\TH:i:s\Z'),
            ]],
        ];
        $attestationPath = $directory.'/attestation.json';
        $trustRootPath = $directory.'/trust-root.json';
        $this->writeSecureJson($attestationPath, $attestation);
        $this->writeSecureJson($trustRootPath, $trustRoot);

        config([
            'app.env' => 'production',
            'operations.build.sha' => $this->commit(),
            'operations.launch_evidence' => $evidence,
            'operations.governance_attestation' => [
                'attestation_path' => $attestationPath,
                'trust_root_path' => $trustRootPath,
                'secret_root' => $directory,
                'maximum_lifetime_hours' => 24,
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function observedErrors(): array
    {
        $trustRootPath = config('operations.governance_attestation.trust_root_path');
        $fingerprint = is_string($trustRootPath) && is_file($trustRootPath)
            ? hash_file('sha256', $trustRootPath)
            : false;

        /** @var list<string> $errors */
        $errors = array_values(collect(
            is_string($fingerprint)
                ? $this->validator->checksAgainstSyntheticTestPin($fingerprint)
                : $this->validator->checks(),
        )
            ->pluck('errors')
            ->flatten()
            ->filter(static fn (mixed $error): bool => is_string($error))
            ->unique()
            ->all());

        return $errors;
    }

    private function temporaryDirectory(): string
    {
        $directory = storage_path(
            'framework/cache/governance-preflight-'.Str::lower(Str::random(20)),
        );
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Temporäres Verzeichnis konnte nicht erstellt werden.');
        }

        return $directory;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function writeSecureJson(string $path, array $document): void
    {
        $encoded = json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (file_put_contents($path, $encoded, LOCK_EX) !== strlen($encoded)) {
            throw new RuntimeException('Synthetische Attestierung konnte nicht geschrieben werden.');
        }
        if (! chmod($path, 0600)) {
            throw new RuntimeException('Synthetische Attestierung konnte nicht abgesichert werden.');
        }
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        foreach (['attestation.json', 'trust-root.json'] as $filename) {
            $path = $directory.'/'.$filename;
            if (is_file($path) && ! is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
