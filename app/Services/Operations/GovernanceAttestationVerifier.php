<?php

namespace App\Services\Operations;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class GovernanceAttestationVerifier
{
    private const BUILD_TRUST_ROOT_PIN = '.erin-governance-trust-root-sha256';

    public function __construct(
        private readonly GovernanceAttestationPayload $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<string>
     */
    public function errors(array $evidence): array
    {
        return $this->errorsWithPinnedFingerprint(
            $evidence,
            $this->embeddedTrustRootFingerprint(),
        );
    }

    /**
     * This entry point exists exclusively for the non-production adversarial
     * control model and unit tests. Launch/readiness commands never call it.
     *
     * @param  array<string, mixed>  $evidence
     * @return list<string>
     */
    public function errorsAgainstSyntheticTestPin(array $evidence, string $fingerprint): array
    {
        $releaseId = $evidence['release']['id'] ?? null;
        if (
            ! app()->runningUnitTests()
            && (! is_string($releaseId) || ! str_starts_with($releaseId, 'erin-adversarial-preflight-'))
        ) {
            return ['attestation_synthetic_test_pin_not_allowed'];
        }

        return $this->errorsWithPinnedFingerprint(
            $evidence,
            preg_match('/\A[0-9a-f]{64}\z/', $fingerprint) === 1
                ? ['fingerprint' => $fingerprint, 'errors' => []]
                : ['fingerprint' => null, 'errors' => ['attestation_trust_root_pin_invalid']],
        );
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array{fingerprint: string|null, errors: list<string>}  $pin
     * @return list<string>
     */
    private function errorsWithPinnedFingerprint(array $evidence, array $pin): array
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return ['attestation_ed25519_unavailable'];
        }

        $attestation = $this->readSecureDocument(
            config('operations.governance_attestation.attestation_path'),
            'attestation',
        );
        $trustRoot = $this->readSecureDocument(
            config('operations.governance_attestation.trust_root_path'),
            'attestation_trust_root',
        );
        $errors = [...$attestation['errors'], ...$trustRoot['errors'], ...$pin['errors']];

        if (
            $attestation['document'] === null
            || $trustRoot['document'] === null
            || $pin['fingerprint'] === null
        ) {
            return array_values(array_unique($errors));
        }

        $document = $attestation['document'];
        $root = $trustRoot['document'];
        $rootFingerprint = hash_file('sha256', $trustRoot['path']);
        if (
            ! is_string($rootFingerprint)
            || ! hash_equals($pin['fingerprint'], $rootFingerprint)
        ) {
            $errors[] = 'attestation_trust_root_pin_mismatch';
        }
        $issuedAt = $this->date($document['issued_at'] ?? null);
        $expiresAt = $this->date($document['expires_at'] ?? null);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $releaseId = $evidence['release']['id'] ?? null;
        $commitSha = $evidence['release']['commit_sha'] ?? null;

        if (($document['schema_version'] ?? null) !== 1) {
            $errors[] = 'attestation_schema_unsupported';
        }
        if (($document['type'] ?? null) !== 'erin_launch_governance_attestation') {
            $errors[] = 'attestation_type_invalid';
        }
        if (($document['algorithm'] ?? null) !== 'Ed25519') {
            $errors[] = 'attestation_algorithm_invalid';
        }
        if (! is_string($releaseId) || ($document['release_id'] ?? null) !== $releaseId) {
            $errors[] = 'attestation_release_mismatch';
        }
        if (! is_string($commitSha) || ($document['commit_sha'] ?? null) !== $commitSha) {
            $errors[] = 'attestation_commit_mismatch';
        }
        if (
            ! is_string($document['evidence_sha256'] ?? null)
            || ! hash_equals($this->payload->evidenceDigest($evidence), $document['evidence_sha256'])
        ) {
            $errors[] = 'attestation_evidence_digest_mismatch';
        }
        if ($issuedAt === null) {
            $errors[] = 'attestation_issued_at_invalid';
        }
        if ($expiresAt === null) {
            $errors[] = 'attestation_expires_at_invalid';
        }

        $maximumLifetime = max(
            1,
            (int) config('operations.governance_attestation.maximum_lifetime_hours', 168),
        );
        if (
            $issuedAt !== null
            && (
                $issuedAt > $now->modify('+5 minutes')
                || $issuedAt < $now->modify("-{$maximumLifetime} hours")
            )
        ) {
            $errors[] = 'attestation_issued_at_outside_window';
        }
        if (
            $issuedAt !== null
            && $expiresAt !== null
            && (
                $expiresAt <= $issuedAt
                || $expiresAt <= $now
                || $expiresAt > $issuedAt->modify("+{$maximumLifetime} hours")
            )
        ) {
            $errors[] = 'attestation_expiry_invalid';
        }

        if (($root['schema_version'] ?? null) !== 1) {
            $errors[] = 'attestation_trust_root_schema_unsupported';
        }
        $errors = [
            ...$errors,
            ...$this->trustRootKeyIdErrors($root['keys'] ?? null),
        ];
        $issuer = $document['issuer'] ?? null;
        if (! is_string($issuer) || $issuer === '' || ($root['issuer'] ?? null) !== $issuer) {
            $errors[] = 'attestation_issuer_untrusted';
        }
        $keyId = $document['key_id'] ?? null;
        $matchingKeys = $this->matchingKeys($root['keys'] ?? null, $keyId);
        if (count($matchingKeys) > 1) {
            $errors[] = 'attestation_key_id_ambiguous';

            return array_values(array_unique($errors));
        }
        $key = $matchingKeys[0] ?? null;
        if ($key === null) {
            $errors[] = 'attestation_key_untrusted';

            return array_values(array_unique($errors));
        }
        if (! in_array($key['status'] ?? null, ['active', 'retiring'], true)) {
            $errors[] = 'attestation_key_inactive';
        }
        if (($key['algorithm'] ?? null) !== 'Ed25519') {
            $errors[] = 'attestation_key_algorithm_invalid';
        }

        $keyNotBefore = $this->date($key['not_before'] ?? null);
        $keyNotAfter = $this->date($key['not_after'] ?? null);
        if (
            $keyNotBefore === null
            || $keyNotAfter === null
            || $issuedAt === null
            || $expiresAt === null
            || $issuedAt < $keyNotBefore
            || $expiresAt > $keyNotAfter
        ) {
            $errors[] = 'attestation_key_validity_invalid';
        }

        $publicKey = $this->decodeBase64(
            $key['public_key'] ?? null,
            SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
        );
        $signature = $this->decodeBase64(
            $document['signature'] ?? null,
            SODIUM_CRYPTO_SIGN_BYTES,
        );
        if ($publicKey === null) {
            $errors[] = 'attestation_public_key_invalid';
        }
        if ($signature === null) {
            $errors[] = 'attestation_signature_invalid';
        }
        if (
            $publicKey !== null
            && $signature !== null
            && ! sodium_crypto_sign_verify_detached(
                $signature,
                $this->payload->signingMessage($document),
                $publicKey,
            )
        ) {
            $errors[] = 'attestation_signature_verification_failed';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return array{document: array<string, mixed>|null, path: string|null, errors: list<string>}
     */
    private function readSecureDocument(mixed $configuredPath, string $errorPrefix): array
    {
        if (! is_string($configuredPath) || $configuredPath === '') {
            return [
                'document' => null,
                'path' => null,
                'errors' => ["{$errorPrefix}_path_missing"],
            ];
        }

        $root = config('operations.governance_attestation.secret_root');
        $realRoot = is_string($root) ? realpath($root) : false;
        $realPath = realpath($configuredPath);
        if (
            $realRoot === false
            || $realPath === false
            || ! str_starts_with($realPath, $realRoot.DIRECTORY_SEPARATOR)
            || ! is_file($realPath)
            || is_link($configuredPath)
            || ! is_readable($realPath)
        ) {
            return [
                'document' => null,
                'path' => null,
                'errors' => ["{$errorPrefix}_file_missing_or_unsafe"],
            ];
        }

        $permissions = fileperms($realPath);
        if (
            ! is_int($permissions)
            || ($permissions & 0022) !== 0
            || ($permissions & 0111) !== 0
        ) {
            return [
                'document' => null,
                'path' => null,
                'errors' => ["{$errorPrefix}_permissions_unsafe"],
            ];
        }

        $size = filesize($realPath);
        if (! is_int($size) || $size < 2 || $size > 65_536) {
            return [
                'document' => null,
                'path' => null,
                'errors' => ["{$errorPrefix}_size_invalid"],
            ];
        }

        $contents = file_get_contents($realPath);
        if (! is_string($contents)) {
            return [
                'document' => null,
                'path' => null,
                'errors' => ["{$errorPrefix}_unreadable"],
            ];
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'document' => null,
                'path' => null,
                'errors' => ["{$errorPrefix}_json_invalid"],
            ];
        }

        if (! is_array($decoded)) {
            return [
                'document' => null,
                'path' => null,
                'errors' => ["{$errorPrefix}_document_invalid"],
            ];
        }

        return ['document' => $decoded, 'path' => $realPath, 'errors' => []];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matchingKeys(mixed $keys, mixed $keyId): array
    {
        if (! is_array($keys) || ! is_string($keyId) || $keyId === '') {
            return [];
        }

        $matches = [];
        foreach ($keys as $key) {
            if (is_array($key) && ($key['key_id'] ?? null) === $keyId) {
                $matches[] = $key;
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function trustRootKeyIdErrors(mixed $keys): array
    {
        if (! is_array($keys)) {
            return ['attestation_trust_root_keys_invalid'];
        }

        $seen = [];
        $errors = [];
        foreach ($keys as $key) {
            $keyId = is_array($key) ? ($key['key_id'] ?? null) : null;
            if (! is_string($keyId) || $keyId === '') {
                $errors[] = 'attestation_trust_root_key_id_invalid';

                continue;
            }
            if (isset($seen[$keyId])) {
                $errors[] = 'attestation_trust_root_key_id_duplicate';
            }
            $seen[$keyId] = true;
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return array{fingerprint: string|null, errors: list<string>}
     */
    private function embeddedTrustRootFingerprint(): array
    {
        $path = base_path(self::BUILD_TRUST_ROOT_PIN);
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            return [
                'fingerprint' => null,
                'errors' => ['attestation_trust_root_build_pin_missing'],
            ];
        }

        $permissions = fileperms($path);
        if (
            ! is_int($permissions)
            || ($permissions & 0222) !== 0
            || ($permissions & 0111) !== 0
        ) {
            return [
                'fingerprint' => null,
                'errors' => ['attestation_trust_root_build_pin_permissions_unsafe'],
            ];
        }

        $contents = file_get_contents($path);
        $fingerprint = is_string($contents) ? trim($contents) : null;
        if (! is_string($fingerprint) || preg_match('/\A[0-9a-f]{64}\z/', $fingerprint) !== 1) {
            return [
                'fingerprint' => null,
                'errors' => ['attestation_trust_root_build_pin_invalid'],
            ];
        }

        return ['fingerprint' => $fingerprint, 'errors' => []];
    }

    /**
     * @return non-empty-string|null
     */
    private function decodeBase64(mixed $value, int $expectedLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $decoded = base64_decode($value, true);

        if (
            ! is_string($decoded)
            || $decoded === ''
            || strlen($decoded) !== $expectedLength
        ) {
            return null;
        }

        return $decoded;
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
