<?php

namespace App\Services\Billing;

use App\Contracts\StripeSubscriptionGateway;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\PlanStripePrice;
use App\Models\StripeAddonPrice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;
use RuntimeException;

class StripeSubscriptionSynchronizer
{
    public function __construct(
        private readonly StripeSubscriptionGateway $gateway,
        private readonly StripeSubscriptionItemClassifier $itemsClassifier,
    ) {}

    /**
     * Synchronize from Stripe's canonical subscription state, never from the
     * potentially stale event object.
     *
     * @param  array<string, mixed>  $payload
     */
    public function synchronize(array $payload): void
    {
        $eventSubscription = $payload['data']['object'] ?? null;
        if (! is_array($eventSubscription)) {
            throw new RuntimeException('Das Stripe-Ereignis enthält kein gültiges Abonnement.');
        }

        $subscriptionId = $this->externalId($eventSubscription['id'] ?? null);
        $customerId = $this->externalId($eventSubscription['customer'] ?? null);
        if ($subscriptionId === null || $customerId === null) {
            throw new RuntimeException('Das Stripe-Abonnement enthält keine gültige ID oder Kunden-ID.');
        }

        $this->synchronizeCanonical($subscriptionId, $customerId);
    }

    /**
     * Schedule events carry the canonical subscription reference under
     * `subscription`; released schedules use `released_subscription`.
     *
     * @param  array<string, mixed>  $payload
     */
    public function synchronizeSchedule(array $payload): void
    {
        $schedule = $payload['data']['object'] ?? null;
        if (! is_array($schedule)) {
            throw new RuntimeException(
                'Das Stripe-Ereignis enthält keinen gültigen Schedule.',
            );
        }

        $subscriptionId = $this->externalId(
            $schedule['subscription']
                ?? $schedule['released_subscription']
                ?? null,
        );
        $customerId = $this->externalId($schedule['customer'] ?? null);
        if ($subscriptionId === null || $customerId === null) {
            throw new RuntimeException(
                'Der Stripe-Schedule enthält keine gültige Abonnement- oder Kunden-ID.',
            );
        }

        $this->synchronizeCanonical($subscriptionId, $customerId);
    }

    private function synchronizeCanonical(
        string $subscriptionId,
        string $customerId,
    ): void {
        $companyId = Company::query()
            ->where('stripe_id', $customerId)
            ->value('id');
        if (! is_numeric($companyId)) {
            return;
        }

        $lock = Cache::lock(
            'stripe-subscription-customer:'.hash('sha256', $customerId),
            120,
        );

        try {
            $lock->block(15, function () use (
                $companyId,
                $customerId,
                $subscriptionId,
            ): void {
                $target = $this->gateway->retrieve($subscriptionId);
                $this->assertSnapshot($target, $subscriptionId, $customerId);

                /** @var Company $companySnapshot */
                $companySnapshot = Company::query()->findOrFail((int) $companyId);
                $expectedCurrentId = $companySnapshot->stripe_subscription_id;
                if (! $this->isCompanySubscription($companySnapshot, $target)) {
                    if ($expectedCurrentId === $subscriptionId) {
                        throw new RuntimeException(
                            'Das aktuelle Stripe-Abonnement enthält kein gültiges Erin-Basispaket.',
                        );
                    }

                    return;
                }

                $current = null;
                if (
                    $expectedCurrentId !== null
                    && $expectedCurrentId !== $subscriptionId
                ) {
                    $current = $this->gateway->retrieve($expectedCurrentId);
                    $this->assertSnapshot($current, $expectedCurrentId, $customerId);
                }

                DB::transaction(function () use (
                    $companyId,
                    $target,
                    $current,
                    $expectedCurrentId,
                ): void {
                    /** @var Company $company */
                    $company = Company::query()
                        ->lockForUpdate()
                        ->findOrFail((int) $companyId);
                    if ($company->stripe_subscription_id !== $expectedCurrentId) {
                        throw new RuntimeException(
                            'Das aktuelle Stripe-Abonnement wurde parallel geändert.',
                        );
                    }

                    $this->syncCashierSubscription($company, $target);
                    $selected = $target;

                    if ($current !== null) {
                        $this->syncCashierSubscription($company, $current);
                        $selected = $this->selectCompanySubscription(
                            $company,
                            $current,
                            $target,
                        );
                    }

                    $this->syncCompany($company, $selected);
                }, 3);
            });
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'Das Stripe-Abonnement wird bereits synchronisiert.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function syncCashierSubscription(
        Company $company,
        array $snapshot,
    ): ?Subscription {
        $subscriptionId = $this->requiredExternalId($snapshot['id'] ?? null, 'Abonnement');
        $status = (string) ($snapshot['status'] ?? 'incomplete');

        /** @var Subscription|null $existing */
        $existing = $company->subscriptions()
            ->where('stripe_id', $subscriptionId)
            ->first();

        if ($status === 'incomplete_expired') {
            if ($existing !== null) {
                $existing->items()->delete();
                $existing->delete();
            }

            return null;
        }

        $items = $this->items($snapshot);
        $baseItem = $this->itemsClassifier
            ->classify($items)['base_item'];
        $firstItem = $items[0] ?? null;
        $trialEndsAt = $this->timestamp($snapshot['trial_end'] ?? null);
        $periodStart = $this->periodTimestamp(
            $baseItem,
            'current_period_start',
        );
        $periodEnd = $this->periodTimestamp(
            $baseItem,
            'current_period_end',
        );
        $isSinglePrice = count($items) === 1;
        $metadata = is_array($snapshot['metadata'] ?? null)
            ? $snapshot['metadata']
            : [];

        /** @var Subscription $subscription */
        $subscription = $company->subscriptions()->updateOrCreate(
            ['stripe_id' => $subscriptionId],
            [
                'type' => (string) (
                    $metadata['type']
                    ?? $metadata['name']
                    ?? 'default'
                ),
                'stripe_status' => $status,
                'stripe_price' => $isSinglePrice
                    ? $this->priceId($firstItem)
                    : null,
                'quantity' => $isSinglePrice
                    ? $this->positiveIntegerOrNull(
                        $firstItem['quantity'] ?? null,
                        'Abonnementmenge',
                    )
                    : null,
                'trial_ends_at' => $trialEndsAt,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'cancel_at_period_end' => (bool) ($snapshot['cancel_at_period_end'] ?? false),
                'ends_at' => $this->subscriptionEndsAt(
                    $snapshot,
                    $status,
                    $trialEndsAt,
                    $periodEnd,
                ),
            ],
        );

        $itemIds = [];
        foreach ($items as $item) {
            $itemId = $this->requiredExternalId($item['id'] ?? null, 'Abonnementposition');
            $price = is_array($item['price'] ?? null) ? $item['price'] : [];
            $priceId = $this->requiredExternalId($price['id'] ?? null, 'Preis');
            $productId = $this->requiredExternalId($price['product'] ?? null, 'Produkt');
            $itemIds[] = $itemId;

            $subscription->items()->updateOrCreate(
                ['stripe_id' => $itemId],
                [
                    'stripe_product' => $productId,
                    'stripe_price' => $priceId,
                    'quantity' => $this->positiveIntegerOrNull(
                        $item['quantity'] ?? null,
                        'Abonnementpositionsmenge',
                    ),
                ],
            );
        }
        $subscription->items()->whereNotIn('stripe_id', $itemIds)->delete();

        if ($company->trial_ends_at !== null) {
            $company->forceFill(['trial_ends_at' => null])->save();
        }

        return $subscription;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function selectCompanySubscription(
        Company $company,
        array $current,
        array $target,
    ): array {
        if (! $this->isCompanySubscription($company, $target)) {
            return $current;
        }

        $currentPlan = $this->planFor($current);
        if ($currentPlan === null) {
            return $target;
        }

        $currentHasAccess = $this->hasPortalStatus($current);
        $targetHasAccess = $this->hasPortalStatus($target);
        $currentGeneration = $this->subscriptionGeneration($current);
        $targetGeneration = $this->subscriptionGeneration($target);
        if ($targetGeneration !== $currentGeneration) {
            if ($targetGeneration < $currentGeneration) {
                return $current;
            }

            return $targetHasAccess || ! $currentHasAccess
                ? $target
                : $current;
        }

        if ($currentHasAccess !== $targetHasAccess) {
            return $targetHasAccess ? $target : $current;
        }

        $currentCreated = $this->integerOrNull($current['created'] ?? null) ?? 0;
        $targetCreated = $this->integerOrNull($target['created'] ?? null) ?? 0;
        if ($targetCreated > $currentCreated) {
            return $target;
        }

        if ($targetCreated < $currentCreated) {
            return $current;
        }

        return $this->externalId($current['id'] ?? null) === $company->stripe_subscription_id
            ? $current
            : $target;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function syncCompany(Company $company, array $snapshot): void
    {
        $plan = $this->planFor($snapshot);
        $status = (string) ($snapshot['status'] ?? 'incomplete');
        $items = $this->items($snapshot);
        $baseItem = $this->itemsClassifier
            ->classify($items)['base_item'];
        $periodStart = $this->periodTimestamp(
            $baseItem,
            'current_period_start',
        );
        $periodEnd = $this->periodTimestamp(
            $baseItem,
            'current_period_end',
        );
        $trialEndsAt = $this->timestamp($snapshot['trial_end'] ?? null);
        $hasAccess = $this->hasPortalStatus($snapshot);
        $pendingState = $this->canonicalPendingState($snapshot, $plan);
        $pendingPlanId = $pendingState['known']
            ? $pendingState['plan']?->getKey()
            : $company->pending_plan_id;
        $pendingEffectiveAt = $pendingState['known']
            ? $pendingState['effective_at']
            : $company->pending_plan_effective_at;

        $company->forceFill([
            'current_plan_id' => $plan?->getKey() ?? $company->current_plan_id,
            'pending_plan_id' => $pendingPlanId,
            'pending_plan_effective_at' => $pendingEffectiveAt,
            'stripe_subscription_id' => $this->requiredExternalId(
                $snapshot['id'] ?? null,
                'Abonnement',
            ),
            'stripe_subscription_generation' => $this->subscriptionGeneration(
                $snapshot,
            ),
            'stripe_next_subscription_generation' => max(
                $company->stripe_next_subscription_generation,
                $this->subscriptionGeneration($snapshot),
            ),
            'subscription_status' => $status,
            'subscription_started_at' => $periodStart
                ?? $company->subscription_started_at,
            'subscription_renews_at' => $periodEnd
                ?? $company->subscription_renews_at,
            'cancel_at_period_end' => (bool) ($snapshot['cancel_at_period_end'] ?? false),
            'subscription_ends_at' => $this->subscriptionEndsAt(
                $snapshot,
                $status,
                $trialEndsAt,
                $periodEnd,
            ),
            'status' => $hasAccess && $company->status === CompanyStatus::Pending
                ? CompanyStatus::Active
                : $company->status,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     known: bool,
     *     plan: Plan|null,
     *     effective_at: CarbonImmutable|null
     * }
     */
    private function canonicalPendingState(
        array $snapshot,
        ?Plan $currentPlan,
    ): array {
        $pendingUpdate = $snapshot['pending_update'] ?? null;
        if ($pendingUpdate !== null) {
            if (! is_array($pendingUpdate)) {
                return [
                    'known' => false,
                    'plan' => null,
                    'effective_at' => null,
                ];
            }

            $items = $pendingUpdate['subscription_items'] ?? null;
            if (! is_array($items)) {
                throw new RuntimeException(
                    'Das kanonische Stripe-Pending-Update enthält keine Positionen.',
                );
            }
            $plan = $this->planForScheduledItems(array_values($items));

            return [
                'known' => true,
                'plan' => $plan->is($currentPlan) ? null : $plan,
                'effective_at' => null,
            ];
        }

        $schedule = $snapshot['schedule'] ?? null;
        if ($schedule === null) {
            return [
                'known' => true,
                'plan' => null,
                'effective_at' => null,
            ];
        }
        if (! is_array($schedule)) {
            return [
                'known' => false,
                'plan' => null,
                'effective_at' => null,
            ];
        }
        if (in_array(
            $schedule['status'] ?? null,
            ['canceled', 'completed', 'released'],
            true,
        )) {
            return [
                'known' => true,
                'plan' => null,
                'effective_at' => null,
            ];
        }

        $phases = $schedule['phases'] ?? null;
        $currentPhase = $schedule['current_phase'] ?? null;
        if (! is_array($phases) || ! is_array($currentPhase)) {
            throw new RuntimeException(
                'Der kanonische Stripe-Schedule enthält keinen vollständigen Phasenplan.',
            );
        }
        $currentStart = $this->integerOrNull(
            $currentPhase['start_date'] ?? null,
        );
        $currentEnd = $this->integerOrNull(
            $currentPhase['end_date'] ?? null,
        );
        if (
            $currentStart === null
            || $currentEnd === null
            || $currentEnd <= $currentStart
        ) {
            throw new RuntimeException(
                'Die aktuelle Stripe-Schedule-Phase ist ungültig.',
            );
        }

        $currentIndex = null;
        foreach (array_values($phases) as $index => $phase) {
            if (
                is_array($phase)
                && $this->integerOrNull($phase['start_date'] ?? null)
                    === $currentStart
                && $this->integerOrNull($phase['end_date'] ?? null)
                    === $currentEnd
            ) {
                $currentIndex = $index;
                break;
            }
        }
        if ($currentIndex === null) {
            throw new RuntimeException(
                'Die aktuelle Stripe-Schedule-Phase fehlt im kanonischen Phasenplan.',
            );
        }

        $previousEnd = $currentEnd;
        foreach (
            array_slice(array_values($phases), $currentIndex + 1) as $phase
        ) {
            if (! is_array($phase)) {
                throw new RuntimeException(
                    'Der Stripe-Schedule enthält eine ungültige Zukunftsphase.',
                );
            }
            $items = $phase['items'] ?? null;
            if (! is_array($items)) {
                throw new RuntimeException(
                    'Eine Stripe-Schedule-Zukunftsphase enthält keine Positionen.',
                );
            }
            $plan = $this->planForScheduledItems(array_values($items));
            $effectiveAt = $this->integerOrNull(
                $phase['start_date'] ?? null,
            ) ?? $previousEnd;
            if (! $plan->is($currentPlan)) {
                return [
                    'known' => true,
                    'plan' => $plan,
                    'effective_at' => CarbonImmutable::createFromTimestamp(
                        $effectiveAt,
                        config('app.timezone'),
                    ),
                ];
            }
            $previousEnd = $this->integerOrNull(
                $phase['end_date'] ?? null,
            ) ?? $previousEnd;
        }

        return [
            'known' => true,
            'plan' => null,
            'effective_at' => null,
        ];
    }

    /**
     * @param  list<mixed>  $items
     */
    private function planForScheduledItems(array $items): Plan
    {
        $priceIds = [];
        $quantities = [];
        foreach ($items as $item) {
            $priceId = $this->externalId(data_get($item, 'price'));
            if ($priceId === null || isset($priceIds[$priceId])) {
                throw new RuntimeException(
                    'Eine Stripe-Schedule-Phase enthält eine ungültige oder doppelte Price.',
                );
            }
            $priceIds[$priceId] = true;
            $quantities[$priceId] = $this->positiveIntegerOrNull(
                data_get($item, 'quantity'),
                'Schedule-Positionsmenge',
            );
        }

        $basePlans = [];
        PlanStripePrice::query()
            ->with('plan')
            ->whereIn('stripe_price_id', array_keys($priceIds))
            ->get()
            ->each(function (PlanStripePrice $price) use (&$basePlans): void {
                $basePlans[$price->stripe_price_id] = $price->plan;
            });
        Plan::query()
            ->whereIn('stripe_price_id', array_keys($priceIds))
            ->get()
            ->each(function (Plan $plan) use (&$basePlans): void {
                $basePlans[(string) $plan->stripe_price_id] = $plan;
            });

        $addOnPrices = StripeAddonPrice::query()
            ->whereIn('stripe_price_id', array_keys($priceIds))
            ->pluck('stripe_price_id')
            ->flip()
            ->all();
        foreach ([
            config('services.stripe.seat_price_id'),
            config('services.stripe.visa_price_id'),
        ] as $configuredAddOnPrice) {
            if (
                is_string($configuredAddOnPrice)
                && $configuredAddOnPrice !== ''
            ) {
                $addOnPrices[$configuredAddOnPrice] = 0;
            }
        }

        $matched = [];
        foreach (array_keys($priceIds) as $priceId) {
            $plan = $basePlans[$priceId] ?? null;
            $isAddOn = array_key_exists($priceId, $addOnPrices);
            if ($plan instanceof Plan && $isAddOn) {
                throw new RuntimeException(
                    'Eine Stripe-Price ist zugleich als Basispaket und Add-on registriert.',
                );
            }
            if ($plan instanceof Plan) {
                if (($quantities[$priceId] ?? null) !== 1) {
                    throw new RuntimeException(
                        'Ein Erin-Basispaket muss in jeder Stripe-Schedule-Phase die Menge 1 besitzen.',
                    );
                }
                $matched[] = $plan;

                continue;
            }
            if (! $isAddOn) {
                throw new RuntimeException(
                    'Eine Stripe-Schedule-Phase enthält eine nicht freigegebene Add-on-Price.',
                );
            }
        }
        if (count($matched) !== 1) {
            throw new RuntimeException(
                'Eine Stripe-Schedule-Phase enthält nicht genau ein Erin-Basispaket.',
            );
        }

        return $matched[0];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function planFor(array $snapshot): ?Plan
    {
        return $this->itemsClassifier
            ->classify($this->items($snapshot))['base_plan'];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function isCompanySubscription(
        Company $company,
        array $snapshot,
    ): bool {
        if ($this->planFor($snapshot) === null) {
            return false;
        }

        $metadata = is_array($snapshot['metadata'] ?? null)
            ? $snapshot['metadata']
            : [];
        $type = $metadata['type'] ?? $metadata['name'] ?? null;
        $companyId = $metadata['company_id'] ?? null;
        $isCurrent = $this->externalId($snapshot['id'] ?? null)
            === $company->stripe_subscription_id;

        return ($type === 'default' || $isCurrent)
            && ((string) $companyId === (string) $company->getKey() || $isCurrent);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function subscriptionGeneration(array $snapshot): int
    {
        $metadata = is_array($snapshot['metadata'] ?? null)
            ? $snapshot['metadata']
            : [];

        return max(
            0,
            $this->integerOrNull(
                $metadata['erin_subscription_generation'] ?? null,
            ) ?? 0,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function hasPortalStatus(array $snapshot): bool
    {
        return in_array(
            $snapshot['status'] ?? null,
            ['active', 'trialing', 'past_due'],
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function assertSnapshot(
        array $snapshot,
        string $subscriptionId,
        string $customerId,
    ): void {
        if (
            $this->externalId($snapshot['id'] ?? null) !== $subscriptionId
            || $this->externalId($snapshot['customer'] ?? null) !== $customerId
        ) {
            throw new RuntimeException('Stripe hat ein unpassendes Abonnement zurückgegeben.');
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function items(array $snapshot): array
    {
        $items = $snapshot['items']['data'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(
            $items,
            static fn (mixed $item): bool => is_array($item),
        ));
    }

    /**
     * @param  array<string, mixed>|null  $item
     */
    private function priceId(?array $item): ?string
    {
        $price = is_array($item['price'] ?? null) ? $item['price'] : [];

        return $this->externalId($price['id'] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $firstItem
     */
    private function periodTimestamp(
        ?array $firstItem,
        string $key,
    ): ?CarbonImmutable {
        return $this->timestamp($firstItem[$key] ?? null);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function subscriptionEndsAt(
        array $snapshot,
        string $status,
        ?CarbonImmutable $trialEndsAt,
        ?CarbonImmutable $periodEnd,
    ): ?CarbonImmutable {
        if ((bool) ($snapshot['cancel_at_period_end'] ?? false)) {
            return $status === 'trialing' && $trialEndsAt !== null
                ? $trialEndsAt
                : $periodEnd;
        }

        return $this->timestamp(
            $snapshot['ended_at']
                ?? $snapshot['cancel_at']
                ?? $snapshot['canceled_at']
                ?? null,
        );
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        $timestamp = $this->integerOrNull($value);

        return $timestamp === null
            ? null
            : CarbonImmutable::createFromTimestamp(
                $timestamp,
                config('app.timezone'),
            );
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function positiveIntegerOrNull(mixed $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }

        $integer = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if (! is_int($integer)) {
            throw new RuntimeException(
                "Stripe hat keine gültige {$label} zurückgegeben.",
            );
        }

        return $integer;
    }

    private function requiredExternalId(mixed $value, string $label): string
    {
        $id = $this->externalId($value);
        if ($id === null) {
            throw new RuntimeException("Stripe hat keine gültige {$label}-ID zurückgegeben.");
        }

        return $id;
    }

    private function externalId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (
            is_array($value)
            && is_string($value['id'] ?? null)
            && $value['id'] !== ''
        ) {
            return $value['id'];
        }

        return null;
    }
}
