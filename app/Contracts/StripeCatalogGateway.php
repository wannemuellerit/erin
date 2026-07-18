<?php

namespace App\Contracts;

/**
 * @phpstan-type StripeProductParameters array{
 *     name: string,
 *     description?: string,
 *     metadata: array<string, string>
 * }
 * @phpstan-type StripePriceParameters array{
 *     product: string,
 *     currency: string,
 *     unit_amount: int,
 *     nickname: string,
 *     recurring: array{interval: 'month', interval_count: int},
 *     metadata: array<string, string>
 * }
 */
interface StripeCatalogGateway
{
    /**
     * @param  StripeProductParameters  $parameters
     */
    public function createProduct(array $parameters, string $idempotencyKey): string;

    /**
     * @param  StripePriceParameters  $parameters
     */
    public function createPrice(array $parameters, string $idempotencyKey): string;
}
