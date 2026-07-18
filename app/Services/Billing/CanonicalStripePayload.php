<?php

namespace App\Services\Billing;

use JsonException;
use LogicException;

class CanonicalStripePayload
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalizeValue($payload);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode(
                $this->normalize($payload),
                JSON_THROW_ON_ERROR
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $exception) {
            throw new LogicException(
                'Der Stripe-Remote-Payload kann nicht kanonisch gespeichert werden.',
                previous: $exception,
            );
        }
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            if (
                $value === null
                || is_bool($value)
                || is_int($value)
                || is_float($value)
                || is_string($value)
            ) {
                return $value;
            }

            throw new LogicException(
                'Der Stripe-Remote-Payload enthält einen nicht speicherbaren Wert.',
            );
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $entry): mixed => $this->normalizeValue($entry),
                $value,
            );
        }

        ksort($value, SORT_STRING);

        $normalized = [];
        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                throw new LogicException(
                    'Der Stripe-Remote-Payload enthält einen ungültigen Schlüssel.',
                );
            }

            $normalized[$key] = $this->normalizeValue($entry);
        }

        return $normalized;
    }
}
