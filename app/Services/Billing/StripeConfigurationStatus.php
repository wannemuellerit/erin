<?php

namespace App\Services\Billing;

use App\Models\Plan;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StripeConfigurationStatus
{
    /**
     * @param  Collection<int, Plan>  $plans
     * @return array{
     *     mode: 'test'|'live'|'unknown',
     *     publishable_key: bool,
     *     secret_key: bool,
     *     webhook_secret: bool,
     *     seat_price: bool,
     *     visa_price: bool,
     *     launch_prices_configured: int,
     *     launch_prices_total: int,
     *     ready: bool,
     *     plans: list<array{slug: string, name: string, product: bool, price: bool}>
     * }
     */
    public function forPlans(Collection $plans): array
    {
        $secret = (string) config('cashier.secret');
        $publishableKey = filled(config('cashier.key'));
        $secretKey = $secret !== '';
        $webhookSecret = filled(config('cashier.webhook.secret'));
        $launchPlans = $plans
            ->filter(fn (Plan $plan): bool => $plan->is_active && ! $plan->is_enterprise)
            ->values();
        $configured = $launchPlans
            ->filter(fn (Plan $plan): bool => filled($plan->stripe_product_id)
                && filled($plan->stripe_price_id))
            ->count();

        return [
            'mode' => match (true) {
                Str::startsWith($secret, 'sk_test_') => 'test',
                Str::startsWith($secret, 'sk_live_') => 'live',
                default => 'unknown',
            },
            'publishable_key' => $publishableKey,
            'secret_key' => $secretKey,
            'webhook_secret' => $webhookSecret,
            'seat_price' => filled(config('services.stripe.seat_price_id')),
            'visa_price' => filled(config('services.stripe.visa_price_id')),
            'launch_prices_configured' => $configured,
            'launch_prices_total' => $launchPlans->count(),
            'ready' => $publishableKey
                && $secretKey
                && $webhookSecret
                && $launchPlans->isNotEmpty()
                && $configured === $launchPlans->count(),
            'plans' => array_values(
                $launchPlans
                    ->map(fn (Plan $plan): array => [
                        'slug' => $plan->slug,
                        'name' => $plan->name,
                        'product' => filled($plan->stripe_product_id),
                        'price' => filled($plan->stripe_price_id),
                    ])
                    ->all(),
            ),
        ];
    }
}
