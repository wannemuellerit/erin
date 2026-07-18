<?php

namespace App\Services\Billing;

use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use LogicException;
use Stripe\StripeClient;

class StripeReadinessProbe
{
    /**
     * Read one Stripe Price without creating or changing remote state.
     *
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
        $price = $this->client()
            ->prices
            ->retrieve($priceId, [])
            ->toArray();
        $product = $price['product'] ?? null;
        $recurring = is_array($price['recurring'] ?? null)
            ? $price['recurring']
            : null;

        return [
            'id' => $this->stringOrNull($price['id'] ?? null),
            'livemode' => (bool) ($price['livemode'] ?? false),
            'active' => (bool) ($price['active'] ?? false),
            'currency' => $this->stringOrNull($price['currency'] ?? null),
            'unit_amount' => is_numeric($price['unit_amount'] ?? null)
                ? (int) $price['unit_amount']
                : null,
            'product_id' => $this->stringOrNull(
                is_array($product) ? ($product['id'] ?? null) : $product,
            ),
            'recurring' => $recurring === null
                ? null
                : [
                    'interval' => $this->stringOrNull(
                        $recurring['interval'] ?? null,
                    ),
                    'interval_count' => is_numeric(
                        $recurring['interval_count'] ?? null,
                    )
                        ? (int) $recurring['interval_count']
                        : null,
                ],
        ];
    }

    /**
     * List Stripe webhook endpoints without returning query strings,
     * fragments or URL credentials to the caller.
     *
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
        $endpoints = [];
        $collection = $this->client()
            ->webhookEndpoints
            ->all(['limit' => 100]);

        foreach ($collection->autoPagingIterator() as $endpoint) {
            $endpoints[] = $this->normalizeWebhookEndpoint(
                $endpoint->toArray(),
            );
        }

        return $endpoints;
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
    protected function normalizeWebhookEndpoint(array $payload): array
    {
        $url = $this->stringOrNull($payload['url'] ?? null);
        $parts = $url === null ? false : parse_url($url);
        $events = $payload['enabled_events'] ?? [];

        return [
            'livemode' => (bool) ($payload['livemode'] ?? false),
            'status' => $this->stringOrNull($payload['status'] ?? null),
            'scheme' => is_array($parts)
                ? $this->stringOrNull($parts['scheme'] ?? null)
                : null,
            'host' => is_array($parts)
                ? $this->stringOrNull($parts['host'] ?? null)
                : null,
            'port' => is_array($parts) && is_numeric($parts['port'] ?? null)
                ? (int) $parts['port']
                : null,
            'path' => is_array($parts)
                && is_string($parts['path'] ?? null)
                    ? $parts['path']
                    : '/',
            'has_query' => is_array($parts)
                && array_key_exists('query', $parts),
            'has_fragment' => is_array($parts)
                && array_key_exists('fragment', $parts),
            'has_credentials' => is_array($parts)
                && (
                    array_key_exists('user', $parts)
                    || array_key_exists('pass', $parts)
                ),
            'enabled_events' => array_values(array_filter(
                is_array($events) ? $events : [],
                static fn (mixed $event): bool => is_string($event),
            )),
        ];
    }

    private function client(): StripeClient
    {
        $secret = (string) config('cashier.secret');
        if (! Str::startsWith($secret, 'sk_test_')) {
            throw new LogicException(
                'Der lesende Stripe-Probe ist ausschließlich mit einem Test-Secret-Key erlaubt.',
            );
        }

        return Cashier::stripe();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
