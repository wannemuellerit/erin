<?php

namespace Tests\Support;

use App\Contracts\StripeCatalogGateway;

/**
 * @phpstan-import-type StripeProductParameters from StripeCatalogGateway
 * @phpstan-import-type StripePriceParameters from StripeCatalogGateway
 */
class ErinStripeCatalogGateway implements StripeCatalogGateway
{
    /** @var list<array{parameters: StripeProductParameters, idempotency_key: string}> */
    public array $productCalls = [];

    /** @var list<array{parameters: StripePriceParameters, idempotency_key: string}> */
    public array $priceCalls = [];

    public function __construct(
        public string $productId = 'prod_erin_test',
        public string $priceId = 'price_erin_test',
    ) {}

    /**
     * @param  StripeProductParameters  $parameters
     */
    public function createProduct(array $parameters, string $idempotencyKey): string
    {
        $this->productCalls[] = [
            'parameters' => $parameters,
            'idempotency_key' => $idempotencyKey,
        ];

        return $this->productId;
    }

    /**
     * @param  StripePriceParameters  $parameters
     */
    public function createPrice(array $parameters, string $idempotencyKey): string
    {
        $this->priceCalls[] = [
            'parameters' => $parameters,
            'idempotency_key' => $idempotencyKey,
        ];

        return $this->priceId;
    }
}
