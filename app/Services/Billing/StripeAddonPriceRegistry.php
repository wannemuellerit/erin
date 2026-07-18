<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\PlanStripePrice;
use App\Models\StripeAddonPrice;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

class StripeAddonPriceRegistry
{
    /**
     * @return list<StripeAddonPrice>
     */
    public function synchronizeConfiguredAddOns(): array
    {
        return array_values(array_filter([
            $this->synchronizeConfiguredRecruiterSeat(),
            $this->synchronizeConfiguredVisaPackage(),
        ]));
    }

    public function synchronizeConfiguredRecruiterSeat(): ?StripeAddonPrice
    {
        $priceId = config('services.stripe.seat_price_id');
        if (! is_string($priceId) || $priceId === '') {
            return null;
        }

        $productId = config('services.stripe.seat_product_id');

        return $this->record(
            'recruiter_seat',
            $priceId,
            is_string($productId) && $productId !== ''
                ? $productId
                : null,
        );
    }

    public function synchronizeConfiguredVisaPackage(): ?StripeAddonPrice
    {
        $priceId = config('services.stripe.visa_price_id');
        if (! is_string($priceId) || $priceId === '') {
            return null;
        }

        $productId = config('services.stripe.visa_product_id');

        return $this->record(
            'visa_package',
            $priceId,
            is_string($productId) && $productId !== ''
                ? $productId
                : null,
        );
    }

    public function record(
        string $code,
        string $priceId,
        ?string $productId,
    ): StripeAddonPrice {
        if (
            preg_match('/\A[a-z0-9_]{1,80}\z/', $code) !== 1
            || preg_match('/\Aprice_[A-Za-z0-9_]+\z/', $priceId) !== 1
            || (
                $productId !== null
                && preg_match('/\Aprod_[A-Za-z0-9_]+\z/', $productId) !== 1
            )
        ) {
            throw new LogicException(
                'Die Stripe-Add-on-Konfiguration enthält ungültige IDs.',
            );
        }

        try {
            return Cache::lock(
                $this->roleLockKey($priceId),
                30,
            )->block(10, function () use (
                $code,
                $priceId,
                $productId,
            ): StripeAddonPrice {
                return DB::transaction(function () use (
                    $code,
                    $priceId,
                    $productId,
                ): StripeAddonPrice {
                    if (
                        PlanStripePrice::query()
                            ->where('stripe_price_id', $priceId)
                            ->exists()
                        || Plan::query()
                            ->where('stripe_price_id', $priceId)
                            ->exists()
                    ) {
                        throw new LogicException(
                            'Eine Stripe-Price darf nicht zugleich als Add-on und Basispaket registriert werden.',
                        );
                    }

                    $now = now();
                    /** @var StripeAddonPrice|null $existing */
                    $existing = StripeAddonPrice::query()
                        ->where('stripe_price_id', $priceId)
                        ->lockForUpdate()
                        ->first();
                    if (
                        $existing !== null
                        && (
                            $existing->code !== $code
                            || (
                                $existing->stripe_product_id !== null
                                && $productId !== null
                                && $existing->stripe_product_id !== $productId
                            )
                        )
                    ) {
                        throw new LogicException(
                            'Eine Stripe-Add-on-Price ist bereits widersprüchlich registriert.',
                        );
                    }

                    StripeAddonPrice::query()
                        ->where('code', $code)
                        ->where('stripe_price_id', '!=', $priceId)
                        ->whereNull('retired_at')
                        ->update([
                            'retired_at' => $now,
                            'updated_at' => $now,
                        ]);

                    if ($existing !== null) {
                        $existing->forceFill([
                            'stripe_product_id' => $existing->stripe_product_id
                                ?? $productId,
                            'is_enabled' => true,
                            'retired_at' => null,
                        ])->save();

                        return $existing;
                    }

                    /** @var StripeAddonPrice $version */
                    $version = StripeAddonPrice::query()->create([
                        'code' => $code,
                        'stripe_product_id' => $productId,
                        'stripe_price_id' => $priceId,
                        'is_enabled' => true,
                        'activated_at' => $now,
                        'retired_at' => null,
                    ]);

                    return $version;
                }, 3);
            });
        } catch (LockTimeoutException $exception) {
            throw new LogicException(
                'Die Stripe-Price-Rolle wird bereits parallel registriert.',
                previous: $exception,
            );
        }
    }

    private function roleLockKey(string $priceId): string
    {
        return 'stripe-price-role:'.hash('sha256', $priceId);
    }
}
