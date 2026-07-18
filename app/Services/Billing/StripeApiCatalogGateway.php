<?php

namespace App\Services\Billing;

use App\Contracts\StripeCatalogGateway;
use LogicException;
use Stripe\StripeClient;

/**
 * @phpstan-import-type StripeProductParameters from StripeCatalogGateway
 * @phpstan-import-type StripePriceParameters from StripeCatalogGateway
 */
class StripeApiCatalogGateway implements StripeCatalogGateway
{
    /**
     * @param  StripeProductParameters  $parameters
     */
    public function createProduct(array $parameters, string $idempotencyKey): string
    {
        $product = $this->client()->products->create(
            $parameters,
            ['idempotency_key' => $idempotencyKey],
        );

        return $product->id;
    }

    /**
     * @param  StripePriceParameters  $parameters
     */
    public function createPrice(array $parameters, string $idempotencyKey): string
    {
        $price = $this->client()->prices->create(
            $parameters,
            ['idempotency_key' => $idempotencyKey],
        );

        return $price->id;
    }

    private function client(): StripeClient
    {
        $secret = (string) config('cashier.secret');

        if ($secret === '') {
            throw new LogicException('Der Stripe Secret Key ist nicht konfiguriert.');
        }

        return new StripeClient($secret);
    }
}
