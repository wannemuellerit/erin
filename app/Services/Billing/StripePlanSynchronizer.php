<?php

namespace App\Services\Billing;

use App\Contracts\StripeCatalogGateway;
use App\Models\Plan;
use Illuminate\Support\Str;
use LogicException;

/**
 * @phpstan-import-type StripeProductParameters from StripeCatalogGateway
 * @phpstan-import-type StripePriceParameters from StripeCatalogGateway
 */
class StripePlanSynchronizer
{
    public function __construct(
        private readonly StripeCatalogGateway $gateway,
    ) {}

    /**
     * @return array{
     *     status: 'configured'|'planned'|'applied'|'skipped',
     *     actions: list<string>
     * }
     */
    public function synchronize(Plan $plan, bool $apply = false): array
    {
        if ($plan->is_enterprise || ! $plan->is_active) {
            return ['status' => 'skipped', 'actions' => []];
        }

        $this->assertSynchronizable($plan);

        $actions = [];
        $productId = filled($plan->stripe_product_id)
            ? (string) $plan->stripe_product_id
            : null;

        if ($productId === null) {
            $actions[] = 'create_product';

            if ($apply) {
                $productId = $this->gateway->createProduct(
                    $this->productParameters($plan),
                    sprintf('erin-plan-%d-product-v1', $plan->getKey()),
                );
                $plan->forceFill(['stripe_product_id' => $productId])->save();
            }
        }

        if (blank($plan->stripe_price_id)) {
            $actions[] = 'create_price';

            if ($apply) {
                if ($productId === null) {
                    throw new LogicException('Das Stripe-Produkt konnte nicht bereitgestellt werden.');
                }

                $priceId = $this->gateway->createPrice(
                    $this->priceParameters($plan, $productId),
                    $this->priceIdempotencyKey($plan),
                );
                $plan->forceFill(['stripe_price_id' => $priceId])->save();
            }
        }

        if ($actions === []) {
            return ['status' => 'configured', 'actions' => []];
        }

        return [
            'status' => $apply ? 'applied' : 'planned',
            'actions' => $actions,
        ];
    }

    private function assertSynchronizable(Plan $plan): void
    {
        if ($plan->price_cents === null || $plan->price_cents < 1) {
            throw new LogicException("Paket {$plan->slug}: Für Stripe wird ein positiver Preis benötigt.");
        }

        if ($plan->term_months === null || $plan->term_months < 1 || $plan->term_months > 12) {
            throw new LogicException("Paket {$plan->slug}: Die Stripe-Laufzeit muss zwischen 1 und 12 Monaten liegen.");
        }

        if (filled($plan->stripe_price_id) && blank($plan->stripe_product_id)) {
            throw new LogicException(
                "Paket {$plan->slug}: Eine Price-ID ohne zugehörige Product-ID wird nicht automatisch verändert.",
            );
        }
    }

    /**
     * @return StripeProductParameters
     */
    private function productParameters(Plan $plan): array
    {
        $parameters = [
            'name' => $plan->name,
            'metadata' => [
                'erin_plan_id' => (string) $plan->getKey(),
                'erin_plan_slug' => $plan->slug,
            ],
        ];

        if (filled($plan->description)) {
            $parameters['description'] = (string) $plan->description;
        }

        return $parameters;
    }

    /**
     * @return StripePriceParameters
     */
    private function priceParameters(Plan $plan, string $productId): array
    {
        return [
            'product' => $productId,
            'currency' => Str::lower($plan->currency),
            'unit_amount' => (int) $plan->price_cents,
            'nickname' => sprintf('%s · %d Monate', $plan->name, (int) $plan->term_months),
            'recurring' => [
                'interval' => 'month',
                'interval_count' => (int) $plan->term_months,
            ],
            'metadata' => [
                'erin_plan_id' => (string) $plan->getKey(),
                'erin_plan_slug' => $plan->slug,
                'erin_price_version' => hash(
                    'sha256',
                    implode('|', [
                        $plan->price_cents,
                        Str::upper($plan->currency),
                        $plan->term_months,
                    ]),
                ),
            ],
        ];
    }

    private function priceIdempotencyKey(Plan $plan): string
    {
        return sprintf(
            'erin-plan-%d-price-%s',
            $plan->getKey(),
            substr(hash('sha256', implode('|', [
                $plan->price_cents,
                Str::upper($plan->currency),
                $plan->term_months,
            ])), 0, 24),
        );
    }
}
