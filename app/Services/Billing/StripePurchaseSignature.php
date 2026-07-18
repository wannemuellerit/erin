<?php

namespace App\Services\Billing;

use LogicException;

class StripePurchaseSignature
{
    public const VERSION = 'v1';

    public function sign(int $companyId, int $credits, string $priceId): string
    {
        return hash_hmac(
            'sha256',
            $this->message($companyId, $credits, $priceId),
            $this->signingKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function verify(array $metadata): bool
    {
        $companyId = $this->positiveInteger($metadata['company_id'] ?? null);
        $credits = $this->positiveInteger($metadata['credits'] ?? null);
        $priceId = $metadata['price_id'] ?? null;
        $signature = $metadata['erin_purchase_signature'] ?? null;

        if (
            ($metadata['erin_signature_version'] ?? null) !== self::VERSION
            || $companyId === null
            || $credits === null
            || $credits > 100
            || ! is_string($priceId)
            || ! str_starts_with($priceId, 'price_')
            || ! is_string($signature)
            || strlen($signature) !== 64
        ) {
            return false;
        }

        return hash_equals(
            $this->sign($companyId, $credits, $priceId),
            $signature,
        );
    }

    private function message(int $companyId, int $credits, string $priceId): string
    {
        return implode('|', [
            self::VERSION,
            (string) $companyId,
            (string) $credits,
            $priceId,
        ]);
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new LogicException('APP_KEY wird für signierte Stripe-Zusatzkäufe benötigt.');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false || $decoded === '') {
                throw new LogicException('APP_KEY besitzt kein gültiges Base64-Format.');
            }
            $key = $decoded;
        }

        return hash('sha256', 'erin-stripe-purchase|'.$key, true);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (
            (! is_int($value) && ! is_string($value))
            || filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            ) === false
        ) {
            return null;
        }

        return (int) $value;
    }
}
