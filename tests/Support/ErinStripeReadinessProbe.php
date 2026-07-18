<?php

namespace Tests\Support;

use App\Services\Billing\StripeReadinessProbe;
use RuntimeException;

class ErinStripeReadinessProbe extends StripeReadinessProbe
{
    /**
     * @var array<string, array{
     *     id: string|null,
     *     livemode: bool,
     *     active: bool,
     *     currency: string|null,
     *     unit_amount: int|null,
     *     product_id: string|null,
     *     recurring: array{interval: string|null, interval_count: int|null}|null
     * }>
     */
    public array $prices = [];

    /** @var list<string> */
    public array $retrieved = [];

    /**
     * @var list<array{
     *     livemode: bool,
     *     status: string|null,
     *     scheme: string|null,
     *     host: string|null,
     *     port: int|null,
     *     path: string,
     *     has_query: bool,
     *     has_fragment: bool,
     *     has_credentials: bool,
     *     enabled_events: list<string>
     * }>
     */
    public array $webhookEndpoints = [];

    public int $webhookListCalls = 0;

    /**
     * @return array{
     *     id: string|null,
     *     livemode: bool,
     *     active: bool,
     *     currency: string|null,
     *     unit_amount: int|null,
     *     product_id: string|null,
     *     recurring: array{interval: string|null, interval_count: int|null}|null
     * }
     */
    public function retrievePrice(string $priceId): array
    {
        $this->retrieved[] = $priceId;

        return $this->prices[$priceId]
            ?? throw new RuntimeException('Test-Price fehlt.');
    }

    /**
     * @return list<array{
     *     livemode: bool,
     *     status: string|null,
     *     scheme: string|null,
     *     host: string|null,
     *     port: int|null,
     *     path: string,
     *     has_query: bool,
     *     has_fragment: bool,
     *     has_credentials: bool,
     *     enabled_events: list<string>
     * }>
     */
    public function listWebhookEndpoints(): array
    {
        $this->webhookListCalls++;

        return $this->webhookEndpoints;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     livemode: bool,
     *     status: string|null,
     *     scheme: string|null,
     *     host: string|null,
     *     port: int|null,
     *     path: string,
     *     has_query: bool,
     *     has_fragment: bool,
     *     has_credentials: bool,
     *     enabled_events: list<string>
     * }
     */
    public function normalizeWebhookPayload(array $payload): array
    {
        return $this->normalizeWebhookEndpoint($payload);
    }
}
