<?php

namespace App\Services\Operations;

use JsonException;

final class GovernanceAttestationPayload
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function evidenceDigest(array $evidence): string
    {
        return hash('sha256', $this->canonicalJson($evidence));
    }

    /**
     * @param  array<string, mixed>  $attestation
     */
    public function signingMessage(array $attestation): string
    {
        unset($attestation['signature']);

        return $this->canonicalJson($attestation);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalJson(array $value): string
    {
        try {
            return json_encode(
                $this->normalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new \RuntimeException('Governance-Evidenz konnte nicht kanonisiert werden.', previous: $exception);
        }
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }
}
