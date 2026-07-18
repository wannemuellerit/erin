<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\PlanStripePrice;
use App\Models\StripeAddonPrice;
use RuntimeException;

class StripeSubscriptionItemClassifier
{
    /**
     * @param  iterable<mixed>  $items
     * @return array{
     *     base_plan: Plan|null,
     *     base_item: array{
     *         id: string,
     *         price: string,
     *         product: string,
     *         quantity: int,
     *         current_period_start: mixed,
     *         current_period_end: mixed
     *     }|null,
     *     add_ons: list<array{
     *         id: string,
     *         price: string,
     *         product: string,
     *         quantity: int,
     *         current_period_start: mixed,
     *         current_period_end: mixed
     *     }>
     * }
     */
    public function classify(iterable $items): array
    {
        $normalizedItems = [];
        $itemIds = [];
        $priceIds = [];

        foreach ($items as $item) {
            $itemId = $this->externalId(data_get($item, 'id'));
            $price = data_get($item, 'price');
            $priceId = $this->externalId($price);
            $productId = $this->externalId(data_get($item, 'price.product'));
            if (
                $itemId === null
                || ! str_starts_with($itemId, 'si_')
                || $priceId === null
                || ! str_starts_with($priceId, 'price_')
                || $productId === null
                || ! str_starts_with($productId, 'prod_')
            ) {
                throw new RuntimeException(
                    'Eine Stripe-Abonnementposition enthält ungültige IDs.',
                );
            }
            if (isset($itemIds[$itemId])) {
                throw new RuntimeException(
                    'Ein Stripe-Abonnement enthält eine doppelte Item-ID.',
                );
            }
            if (isset($priceIds[$priceId])) {
                throw new RuntimeException(
                    'Ein Stripe-Abonnement enthält eine doppelte Price-ID.',
                );
            }
            $itemIds[$itemId] = true;
            $priceIds[$priceId] = true;

            $normalizedItems[] = [
                'id' => $itemId,
                'price' => $priceId,
                'product' => $productId,
                'quantity' => $this->positiveInteger(
                    data_get($item, 'quantity'),
                ),
                'current_period_start' => data_get(
                    $item,
                    'current_period_start',
                ),
                'current_period_end' => data_get(
                    $item,
                    'current_period_end',
                ),
            ];
        }

        $normalizedPriceIds = array_column($normalizedItems, 'price');
        $basePrices = [];
        PlanStripePrice::query()
            ->with('plan')
            ->whereIn('stripe_price_id', $normalizedPriceIds)
            ->get()
            ->each(function (PlanStripePrice $version) use (&$basePrices): void {
                $basePrices[$version->stripe_price_id] = [
                    'plan' => $version->plan,
                    'product' => $version->stripe_product_id,
                ];
            });
        Plan::query()
            ->whereIn('stripe_price_id', $normalizedPriceIds)
            ->get()
            ->each(function (Plan $plan) use (&$basePrices): void {
                $priceId = (string) $plan->stripe_price_id;
                $existing = $basePrices[$priceId] ?? null;
                if (
                    is_array($existing)
                    && $existing['product'] !== $plan->stripe_product_id
                ) {
                    throw new RuntimeException(
                        'Die Stripe-Preisversionen enthalten eine widersprüchliche Produktbindung.',
                    );
                }

                $basePrices[$priceId] = [
                    'plan' => $plan,
                    'product' => $plan->stripe_product_id,
                ];
            });

        $addOnPrices = [];
        StripeAddonPrice::query()
            ->where('is_enabled', true)
            ->whereIn('stripe_price_id', $normalizedPriceIds)
            ->get()
            ->each(function (StripeAddonPrice $addOn) use (&$addOnPrices): void {
                $addOnPrices[$addOn->stripe_price_id] = $addOn->stripe_product_id;
            });
        foreach ([
            [
                config('services.stripe.seat_price_id'),
                config('services.stripe.seat_product_id'),
            ],
            [
                config('services.stripe.visa_price_id'),
                config('services.stripe.visa_product_id'),
            ],
        ] as [$configuredPrice, $configuredProduct]) {
            if (
                is_string($configuredPrice)
                && $configuredPrice !== ''
            ) {
                $addOnPrices[$configuredPrice] = is_string(
                    $configuredProduct,
                ) && $configuredProduct !== ''
                    ? $configuredProduct
                    : null;
            }
        }

        $baseMatches = [];
        $addOns = [];

        foreach ($normalizedItems as $item) {
            $base = $basePrices[$item['price']] ?? null;
            $isAddOn = array_key_exists($item['price'], $addOnPrices);
            if (is_array($base) && $isAddOn) {
                throw new RuntimeException(
                    'Eine Stripe-Price ist zugleich als Basispaket und Add-on registriert.',
                );
            }

            if (! is_array($base)) {
                if (! $isAddOn) {
                    throw new RuntimeException(
                        'Ein Stripe-Abonnement enthält eine nicht freigegebene Add-on-Price.',
                    );
                }
                $expectedProduct = $addOnPrices[$item['price']];
                if (
                    is_string($expectedProduct)
                    && $item['product'] !== $expectedProduct
                ) {
                    throw new RuntimeException(
                        'Stripe-Price und Add-on-Produktzuordnung stimmen nicht überein.',
                    );
                }

                $addOns[] = $item;

                continue;
            }

            if ($item['product'] !== $base['product']) {
                throw new RuntimeException(
                    'Stripe-Price und Erin-Produktzuordnung stimmen nicht überein.',
                );
            }

            if ($item['quantity'] !== 1) {
                throw new RuntimeException(
                    'Ein Erin-Basispaket muss als genau eine Stripe-Position mit Menge 1 vorliegen.',
                );
            }

            $baseMatches[] = [
                'plan' => $base['plan'],
                'item' => $item,
            ];
        }

        if (count($baseMatches) > 1) {
            throw new RuntimeException(
                'Ein Stripe-Abonnement enthält mehrere Erin-Basispakete.',
            );
        }

        return [
            'base_plan' => $baseMatches[0]['plan'] ?? null,
            'base_item' => $baseMatches[0]['item'] ?? null,
            'add_ons' => $addOns,
        ];
    }

    private function positiveInteger(mixed $value): int
    {
        $integer = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if (! is_int($integer)) {
            throw new RuntimeException(
                'Eine Stripe-Abonnementposition enthält keine gültige Menge.',
            );
        }

        return $integer;
    }

    private function externalId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $id = data_get($value, 'id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
