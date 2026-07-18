<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\PlanStripePrice;
use App\Models\StripeAddonPrice;
use Carbon\CarbonImmutable;
use RuntimeException;
use Stripe\StripeObject;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;

class StripeSubscriptionScheduleBuilder
{
    /**
     * Only parameters accepted by Stripe's phase update API are copied from
     * provider snapshots. Output-only response fields never round-trip.
     *
     * @var list<string>
     */
    private const PHASE_FIELDS = [
        'add_invoice_items',
        'application_fee_percent',
        'automatic_tax',
        'billing_cycle_anchor',
        'billing_thresholds',
        'collection_method',
        'currency',
        'default_payment_method',
        'default_tax_rates',
        'description',
        'discounts',
        'invoice_settings',
        'metadata',
        'on_behalf_of',
        'payment_settings',
        'transfer_data',
        'trial',
        'trial_end',
    ];

    /** @var list<string> */
    private const ITEM_FIELDS = [
        'billing_thresholds',
        'discounts',
        'metadata',
        'tax_rates',
    ];

    /**
     * Phase values that remain meaningful when a new, later phase starts.
     * One-time invoice items and trial dates deliberately do not carry over.
     *
     * @var list<string>
     */
    private const INHERITED_PHASE_FIELDS = [
        'application_fee_percent',
        'automatic_tax',
        'billing_cycle_anchor',
        'billing_thresholds',
        'collection_method',
        'currency',
        'default_payment_method',
        'default_tax_rates',
        'description',
        'discounts',
        'invoice_settings',
        'metadata',
        'on_behalf_of',
        'payment_settings',
        'transfer_data',
    ];

    /** @var list<string> */
    private const DEFAULT_SETTING_FIELDS = [
        'application_fee_percent',
        'automatic_tax',
        'billing_cycle_anchor',
        'billing_thresholds',
        'collection_method',
        'default_payment_method',
        'default_source',
        'description',
        'invoice_settings',
        'on_behalf_of',
        'payment_settings',
        'transfer_data',
    ];

    /**
     * Change only the active schedule phase. Existing future phases retain
     * their plan, add-on quantities, tax settings, discounts and boundaries.
     *
     * @return array<string, mixed>
     */
    public function upgradeParameters(
        Subscription $subscription,
        SubscriptionSchedule $schedule,
        string $sourcePriceId,
        string $targetPriceId,
    ): array {
        $this->assertPriceId($sourcePriceId);
        $this->assertPriceId($targetPriceId);
        $this->assertScheduleOwnership($subscription, $schedule);

        [$startDate, $endDate] = $this->currentPhaseBoundary($schedule);
        [$phases, $currentIndex] = $this->activeAndFuturePhases(
            $schedule,
            $startDate,
            $endDate,
        );
        $fallbackItems = $this->subscriptionItems($subscription);
        $result = [];

        foreach ($phases as $index => $phase) {
            $isCurrent = $index === 0;
            $result[] = $this->buildPhase(
                $phase,
                $fallbackItems,
                $isCurrent ? $targetPriceId : null,
                $isCurrent ? $sourcePriceId : null,
                $isCurrent ? $startDate : null,
                $isCurrent ? $endDate : null,
                false,
                null,
            );
        }

        if ($result === [] || $currentIndex < 0) {
            throw new RuntimeException(
                'Der aktive Stripe-Schedule konnte nicht kanonisch aufgebaut werden.',
            );
        }

        return $this->scheduleParameters($schedule, [
            'end_behavior' => $this->endBehavior($schedule),
            'phases' => $result,
            'proration_behavior' => 'always_invoice',
        ]);
    }

    /**
     * Change the first phase after the active one. Later scheduled changes
     * remain untouched. When no next phase exists, append one.
     *
     * @return array<string, mixed>
     */
    public function downgradeParameters(
        Subscription $subscription,
        SubscriptionSchedule $schedule,
        string $sourcePriceId,
        string $targetPriceId,
    ): array {
        $this->assertPriceId($sourcePriceId);
        $this->assertPriceId($targetPriceId);
        $this->assertScheduleOwnership($subscription, $schedule);

        [$startDate, $endDate] = $this->currentPhaseBoundary($schedule);
        [$phases] = $this->activeAndFuturePhases(
            $schedule,
            $startDate,
            $endDate,
        );
        $fallbackItems = $this->subscriptionItems($subscription);
        $result = [];

        foreach ($phases as $index => $phase) {
            $isCurrent = $index === 0;
            $isNext = $index === 1;
            $result[] = $this->buildPhase(
                $phase,
                $fallbackItems,
                $isNext ? $targetPriceId : null,
                $isCurrent ? $sourcePriceId : null,
                $isCurrent ? $startDate : null,
                $isCurrent ? $endDate : null,
                false,
                $isNext ? 'none' : null,
            );
        }

        if (count($result) === 1) {
            $targetEndDate = $this->targetPhaseEnd(
                $endDate,
                $targetPriceId,
            );
            $result[] = $this->buildPhase(
                $this->inheritedPhase($phases[0]),
                $result[0]['items'],
                $targetPriceId,
                $sourcePriceId,
                $endDate,
                $targetEndDate,
                true,
                'none',
            );
        }

        return $this->scheduleParameters($schedule, [
            'end_behavior' => $this->endBehavior($schedule),
            'phases' => $result,
            'proration_behavior' => 'none',
        ]);
    }

    /**
     * Validate the canonical provider snapshots after an update, rather than
     * trusting the mutation response. Returns the future target start for a
     * downgrade and the current phase start for an upgrade.
     *
     * @param  array<string, mixed>  $expectedParameters
     */
    public function assertCanonicalUpdate(
        Subscription $subscription,
        SubscriptionSchedule $schedule,
        array $expectedParameters,
        string $sourcePriceId,
        string $targetPriceId,
        string $changeType,
    ): int {
        $this->assertPriceId($sourcePriceId);
        $this->assertPriceId($targetPriceId);
        $this->assertScheduleOwnership($subscription, $schedule);
        if (
            ! in_array($changeType, ['upgrade', 'downgrade'], true)
            || data_get($schedule->toArray(), 'status') !== 'active'
        ) {
            throw new RuntimeException(
                'Der kanonische Stripe-Schedule besitzt keinen aktiven, unterstützten Zustand.',
            );
        }

        $expectedEndBehavior = $expectedParameters['end_behavior'] ?? null;
        if (
            ! is_string($expectedEndBehavior)
            || $this->endBehavior($schedule) !== $expectedEndBehavior
        ) {
            throw new RuntimeException(
                'Das kanonische Stripe-Schedule-Endverhalten weicht vom Billing-Intent ab.',
            );
        }

        $expectedDefaults = $expectedParameters['default_settings'] ?? [];
        if (
            ! is_array($expectedDefaults)
            || $this->defaultSettings($schedule) !== $expectedDefaults
        ) {
            throw new RuntimeException(
                'Die kanonischen Stripe-Schedule-Abrechnungseinstellungen wurden extern verändert.',
            );
        }

        [$currentStart, $currentEnd] = $this->currentPhaseBoundary(
            $schedule,
        );
        [$canonicalPhases] = $this->activeAndFuturePhases(
            $schedule,
            $currentStart,
            $currentEnd,
        );
        $fallbackItems = $this->subscriptionItems($subscription);
        [, $subscriptionBase] = $this->normalizeItems(
            $fallbackItems,
            null,
            null,
        );
        $downgradeTransitioned = $changeType === 'downgrade'
            && $subscriptionBase === $targetPriceId;
        if (
            ($changeType === 'upgrade'
                && $subscriptionBase !== $targetPriceId)
            || ($changeType === 'downgrade'
                && ! in_array(
                    $subscriptionBase,
                    [$sourcePriceId, $targetPriceId],
                    true,
                ))
        ) {
            throw new RuntimeException(
                'Das kanonische Stripe-Abonnement besitzt nicht den zum Schedule passenden Basistarif.',
            );
        }

        $expectedPhases = $expectedParameters['phases'] ?? null;
        if (
            $downgradeTransitioned
            && is_array($expectedPhases)
            && array_is_list($expectedPhases)
        ) {
            $transitionedIndex = null;
            foreach ($expectedPhases as $index => $phase) {
                if (data_get($phase, 'start_date') === $currentStart) {
                    $transitionedIndex = $index;
                    break;
                }
            }
            if ($transitionedIndex === null) {
                throw new RuntimeException(
                    'Der persistierte Stripe-Schedule enthält keine kanonische bereits gestartete Zielphase.',
                );
            }
            $expectedPhases = array_slice(
                $expectedPhases,
                $transitionedIndex,
            );
        }
        if (
            ! is_array($expectedPhases)
            || ! array_is_list($expectedPhases)
            || count($canonicalPhases) !== count($expectedPhases)
        ) {
            throw new RuntimeException(
                'Der kanonische Stripe-Schedule enthält einen abweichenden Phasenplan.',
            );
        }

        $normalizedCanonical = [];
        $normalizedExpected = [];
        foreach ($canonicalPhases as $index => $phase) {
            $canonicalStart = data_get($phase, 'start_date');
            $canonicalEnd = data_get($phase, 'end_date');
            $expected = $expectedPhases[$index] ?? null;
            if (
                ! is_int($canonicalStart)
                || ! is_int($canonicalEnd)
                || $canonicalStart < 1
                || $canonicalEnd <= $canonicalStart
                || ! is_array($expected)
            ) {
                throw new RuntimeException(
                    'Der kanonische Stripe-Schedule enthält ungültige Phasengrenzen.',
                );
            }
            if (
                $index > 0
                && $canonicalStart
                    !== data_get($canonicalPhases[$index - 1], 'end_date')
            ) {
                throw new RuntimeException(
                    'Die kanonischen Stripe-Schedule-Phasen sind nicht lückenlos.',
                );
            }

            $normalizedCanonical[] = $this->buildPhase(
                $phase,
                $fallbackItems,
                null,
                null,
                $canonicalStart,
                $canonicalEnd,
                false,
                null,
            );
            $expectedStart = data_get($expected, 'start_date');
            $expectedEnd = data_get($expected, 'end_date');
            if (! is_int($expectedStart) || ! is_int($expectedEnd)) {
                throw new RuntimeException(
                    'Der persistierte Stripe-Schedule-Payload enthält keine eindeutigen Phasengrenzen.',
                );
            }
            $normalizedExpected[] = $this->buildPhase(
                $expected,
                $fallbackItems,
                null,
                null,
                $expectedStart,
                $expectedEnd,
                false,
                null,
            );
        }

        if ($normalizedCanonical !== $normalizedExpected) {
            throw new RuntimeException(
                'Der kanonische Stripe-Schedule weicht bei Preisen, Mengen, Grenzen oder Abrechnungsparametern vom Billing-Intent ab.',
            );
        }

        $expectedCurrentBase = data_get(
            $normalizedExpected,
            '0.items.0.price',
        );
        if (
            $expectedCurrentBase !== (
                $changeType === 'upgrade' || $downgradeTransitioned
                    ? $targetPriceId
                    : $sourcePriceId
            )
        ) {
            throw new RuntimeException(
                'Die kanonische aktuelle Stripe-Schedule-Phase besitzt einen falschen Basistarif.',
            );
        }

        if ($changeType === 'upgrade' || $downgradeTransitioned) {
            return $currentStart;
        }

        $futureStart = data_get(
            $normalizedExpected,
            '1.start_date',
        );
        $futureBase = data_get(
            $normalizedExpected,
            '1.items.0.price',
        );
        if (
            ! is_int($futureStart)
            || $futureStart !== $currentEnd
            || $futureBase !== $targetPriceId
        ) {
            throw new RuntimeException(
                'Die kanonische Stripe-Schedule-Zukunftsphase bestätigt den Downgrade nicht.',
            );
        }

        return $futureStart;
    }

    /**
     * Compatibility wrapper for callers that only need the phase list.
     *
     * @return list<array<string, mixed>>
     */
    public function downgradePhases(
        Subscription $subscription,
        SubscriptionSchedule $schedule,
        Plan $currentPlan,
        Plan $targetPlan,
    ): array {
        $parameters = $this->downgradeParameters(
            $subscription,
            $schedule,
            $this->requiredPrice($currentPlan),
            $this->requiredPrice($targetPlan),
        );

        /** @var list<array<string, mixed>> $phases */
        $phases = $parameters['phases'];

        return $phases;
    }

    /**
     * @param  array<string, mixed>  $phase
     * @param  list<mixed>  $fallbackItems
     * @return array<string, mixed>
     */
    private function buildPhase(
        array $phase,
        array $fallbackItems,
        ?string $replacementBasePrice,
        ?string $expectedBasePrice,
        ?int $startDate,
        ?int $endDate,
        bool $appended,
        ?string $prorationBehavior,
    ): array {
        $result = [];

        foreach (self::PHASE_FIELDS as $field) {
            if (array_key_exists($field, $phase) && $phase[$field] !== null) {
                $result[$field] = $this->providerParameter($phase[$field]);
            }
        }

        if ($startDate !== null && $endDate !== null) {
            $result['start_date'] = $startDate;
            $result['end_date'] = $endDate;
        } elseif (! $appended) {
            if (
                array_key_exists('start_date', $phase)
                && is_int($phase['start_date'])
                && $phase['start_date'] > 0
            ) {
                $result['start_date'] = $phase['start_date'];
            }
            foreach (['end_date', 'iterations', 'duration'] as $timingField) {
                if (
                    array_key_exists($timingField, $phase)
                    && $phase[$timingField] !== null
                ) {
                    $result[$timingField] = $this->providerParameter(
                        $phase[$timingField],
                    );
                    break;
                }
            }
        }

        $phaseItems = is_array($phase['items'] ?? null)
            && $phase['items'] !== []
                ? array_values($phase['items'])
                : $fallbackItems;
        [$items] = $this->normalizeItems(
            $phaseItems,
            $replacementBasePrice,
            $expectedBasePrice,
        );
        $result['items'] = $items;

        $originalProration = $phase['proration_behavior'] ?? null;
        $result['proration_behavior'] = $prorationBehavior
            ?? (
                is_string($originalProration)
                && in_array(
                    $originalProration,
                    ['always_invoice', 'create_prorations', 'none'],
                    true,
                )
                    ? $originalProration
                    : 'none'
            );

        return $result;
    }

    /**
     * @param  list<mixed>  $items
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function normalizeItems(
        array $items,
        ?string $replacementBasePrice,
        ?string $expectedBasePrice,
    ): array {
        if ($items === []) {
            throw new RuntimeException(
                'Eine Stripe-Schedule-Phase enthält keine Abonnementpositionen.',
            );
        }

        $priceIds = [];
        foreach ($items as $item) {
            $priceId = $this->externalId(data_get($item, 'price'));
            if ($priceId === null || isset($priceIds[$priceId])) {
                throw new RuntimeException(
                    'Eine Stripe-Schedule-Phase enthält eine ungültige oder doppelte Price.',
                );
            }
            $priceIds[$priceId] = true;
        }

        $basePrices = [];
        PlanStripePrice::query()
            ->whereIn('stripe_price_id', array_keys($priceIds))
            ->get(['stripe_price_id'])
            ->each(function (PlanStripePrice $price) use (&$basePrices): void {
                $basePrices[$price->stripe_price_id] = true;
            });
        Plan::query()
            ->whereIn('stripe_price_id', array_keys($priceIds))
            ->pluck('stripe_price_id')
            ->each(function (string $priceId) use (&$basePrices): void {
                $basePrices[$priceId] = true;
            });

        $addOnPrices = [];
        StripeAddonPrice::query()
            ->whereIn('stripe_price_id', array_keys($priceIds))
            ->pluck('stripe_price_id')
            ->each(function (string $priceId) use (&$addOnPrices): void {
                $addOnPrices[$priceId] = true;
            });
        foreach ([
            config('services.stripe.seat_price_id'),
            config('services.stripe.visa_price_id'),
        ] as $configuredAddOnPrice) {
            if (
                is_string($configuredAddOnPrice)
                && $configuredAddOnPrice !== ''
            ) {
                $addOnPrices[$configuredAddOnPrice] = true;
            }
        }

        $base = null;
        $basePrice = null;
        $addOns = [];

        foreach ($items as $item) {
            $priceId = $this->externalId(data_get($item, 'price'));
            if ($priceId === null) {
                throw new RuntimeException(
                    'Eine Stripe-Schedule-Phase enthält keine Price-ID.',
                );
            }
            if (isset($basePrices[$priceId], $addOnPrices[$priceId])) {
                throw new RuntimeException(
                    'Eine Stripe-Price ist zugleich als Basispaket und Add-on registriert.',
                );
            }

            $normalized = [
                'price' => $priceId,
                'quantity' => $this->positiveInteger(
                    data_get($item, 'quantity'),
                ),
            ];
            foreach (self::ITEM_FIELDS as $field) {
                $value = data_get($item, $field);
                if ($value !== null) {
                    $normalized[$field] = $this->providerParameter($value);
                }
            }

            if (isset($basePrices[$priceId])) {
                if ($base !== null) {
                    throw new RuntimeException(
                        'Eine Stripe-Schedule-Phase enthält mehrere Basispakete.',
                    );
                }
                if ($normalized['quantity'] !== 1) {
                    throw new RuntimeException(
                        'Ein Erin-Basispaket muss in jeder Stripe-Schedule-Phase die Menge 1 besitzen.',
                    );
                }
                $base = $normalized;
                $basePrice = $priceId;

                continue;
            }

            if (! isset($addOnPrices[$priceId])) {
                throw new RuntimeException(
                    'Eine Stripe-Schedule-Phase enthält eine nicht freigegebene Add-on-Price.',
                );
            }
            $addOns[] = $normalized;
        }

        if ($base === null || $basePrice === null) {
            throw new RuntimeException(
                'Eine Stripe-Schedule-Phase enthält kein eindeutiges Erin-Basispaket.',
            );
        }
        if ($expectedBasePrice !== null && $basePrice !== $expectedBasePrice) {
            throw new RuntimeException(
                'Die aktive Stripe-Schedule-Phase entspricht nicht der im Billing-Intent fixierten Price.',
            );
        }
        if ($replacementBasePrice !== null) {
            $base['price'] = $replacementBasePrice;
        }

        usort(
            $addOns,
            static fn (array $left, array $right): int => [
                $left['price'],
                $left['quantity'],
            ] <=> [
                $right['price'],
                $right['quantity'],
            ],
        );

        return [[
            $base,
            ...$addOns,
        ], $basePrice];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function activeAndFuturePhases(
        SubscriptionSchedule $schedule,
        int $startDate,
        int $endDate,
    ): array {
        $phases = $this->phaseSnapshots($schedule);
        if ($phases === []) {
            return [[[
                'start_date' => $startDate,
                'end_date' => $endDate,
                'items' => [],
            ]], 0];
        }

        $currentIndex = $this->currentPhaseIndex(
            $phases,
            $startDate,
            $endDate,
        );

        return [array_slice($phases, $currentIndex), $currentIndex];
    }

    /**
     * @return list<mixed>
     */
    private function subscriptionItems(Subscription $subscription): array
    {
        return array_values($subscription->items->data);
    }

    private function assertScheduleOwnership(
        Subscription $subscription,
        SubscriptionSchedule $schedule,
    ): void {
        $scheduleSnapshot = $schedule->toArray();
        $subscriptionId = $this->externalId($subscription);
        $attachedScheduleId = $this->externalId(
            data_get($subscription->toArray(), 'schedule'),
        );
        $scheduleSubscriptionId = $this->externalId(
            $scheduleSnapshot['subscription'] ?? null,
        );
        if (
            $subscriptionId === null
            || $attachedScheduleId !== $schedule->id
            || $scheduleSubscriptionId !== $subscriptionId
        ) {
            throw new RuntimeException(
                'Der Stripe-Schedule besitzt keine eindeutige Bindung an das Abonnement.',
            );
        }

        $customerId = $this->externalId($subscription->customer);
        $scheduleCustomerId = $this->externalId(
            $scheduleSnapshot['customer'] ?? null,
        );
        if (
            $customerId === null
            || $scheduleCustomerId !== $customerId
        ) {
            throw new RuntimeException(
                'Der Stripe-Schedule besitzt keine eindeutige Kundenbindung.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function scheduleParameters(
        SubscriptionSchedule $schedule,
        array $parameters,
    ): array {
        $defaultSettings = $this->defaultSettings($schedule);
        if ($defaultSettings !== []) {
            $parameters['default_settings'] = $defaultSettings;
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSettings(
        SubscriptionSchedule $schedule,
    ): array {
        $settings = data_get($schedule->toArray(), 'default_settings');
        if (! is_array($settings)) {
            return [];
        }

        $result = [];
        foreach (self::DEFAULT_SETTING_FIELDS as $field) {
            if (
                array_key_exists($field, $settings)
                && $settings[$field] !== null
            ) {
                $result[$field] = $this->providerParameter(
                    $settings[$field],
                );
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $phase
     * @return array<string, mixed>
     */
    private function inheritedPhase(array $phase): array
    {
        $inherited = [];
        foreach (self::INHERITED_PHASE_FIELDS as $field) {
            if (
                array_key_exists($field, $phase)
                && $phase[$field] !== null
            ) {
                $inherited[$field] = $phase[$field];
            }
        }

        return $inherited;
    }

    private function targetPhaseEnd(
        int $startDate,
        string $targetPriceId,
    ): int {
        $termMonths = PlanStripePrice::query()
            ->where('stripe_price_id', $targetPriceId)
            ->value('term_months')
            ?? Plan::query()
                ->where('stripe_price_id', $targetPriceId)
                ->value('term_months');
        if (! is_int($termMonths) || $termMonths < 1) {
            throw new RuntimeException(
                'Für die Stripe-Zielphase fehlt eine unveränderliche Laufzeit.',
            );
        }

        $endDate = CarbonImmutable::createFromTimestampUTC($startDate)
            ->addMonthsNoOverflow($termMonths)
            ->getTimestamp();
        if ($endDate <= $startDate) {
            throw new RuntimeException(
                'Die begrenzte Stripe-Zielphase besitzt keine gültige Laufzeit.',
            );
        }

        return $endDate;
    }

    private function endBehavior(SubscriptionSchedule $schedule): string
    {
        $endBehavior = data_get($schedule->toArray(), 'end_behavior');
        if (
            ! is_string($endBehavior)
            || ! in_array($endBehavior, ['cancel', 'release'], true)
        ) {
            throw new RuntimeException(
                'Der Stripe-Schedule enthält kein unterstütztes Endverhalten.',
            );
        }

        return $endBehavior;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function currentPhaseBoundary(
        SubscriptionSchedule $schedule,
    ): array {
        $startDate = data_get($schedule->current_phase, 'start_date');
        $endDate = data_get($schedule->current_phase, 'end_date');
        if (
            ! is_int($startDate)
            || ! is_int($endDate)
            || $startDate < 1
            || $endDate <= $startDate
        ) {
            throw new RuntimeException(
                'Die aktuelle Stripe-Schedule-Phase ist ungültig.',
            );
        }

        return [$startDate, $endDate];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function phaseSnapshots(
        SubscriptionSchedule $schedule,
    ): array {
        $phases = $schedule->phases ?? [];
        $snapshots = [];

        foreach ($phases as $phase) {
            $value = $this->arrayValue($phase);
            if (is_array($value)) {
                $snapshots[] = $value;
            }
        }

        return $snapshots;
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     */
    private function currentPhaseIndex(
        array $phases,
        int $startDate,
        int $endDate,
    ): int {
        foreach ($phases as $index => $phase) {
            if (
                data_get($phase, 'start_date') === $startDate
                && data_get($phase, 'end_date') === $endDate
            ) {
                return $index;
            }
        }

        throw new RuntimeException(
            'Die aktive Stripe-Schedule-Phase ist im Phasenplan nicht eindeutig enthalten.',
        );
    }

    private function providerParameter(mixed $value): mixed
    {
        $value = $this->arrayValue($value);
        if (! is_array($value)) {
            return $value;
        }

        if (
            isset($value['id'])
            && is_string($value['id'])
            && isset($value['object'])
        ) {
            return $value['id'];
        }

        $result = [];
        $isList = array_is_list($value);
        foreach ($value as $key => $nested) {
            if (in_array($key, [
                'object',
                'created',
                'livemode',
                'deleted',
                'has_more',
                'url',
                'disabled_reason',
            ], true)) {
                continue;
            }
            $converted = $this->providerParameter($nested);
            if ($converted !== null) {
                $result[$key] = $converted;
            }
        }

        return $isList ? array_values($result) : $result;
    }

    private function arrayValue(mixed $value): mixed
    {
        if ($value instanceof StripeObject) {
            return $value->toArray();
        }

        return $value;
    }

    private function requiredPrice(Plan $plan): string
    {
        if (! is_string($plan->stripe_price_id) || $plan->stripe_price_id === '') {
            throw new RuntimeException(
                'Ein Erin-Paket enthält keine gültige Stripe-Price-ID.',
            );
        }

        return $plan->stripe_price_id;
    }

    private function assertPriceId(string $priceId): void
    {
        if (preg_match('/\Aprice_[A-Za-z0-9_]+\z/', $priceId) !== 1) {
            throw new RuntimeException(
                'Der Billing-Intent enthält keine gültige Stripe-Price-ID.',
            );
        }
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
                'Eine Stripe-Schedule-Position enthält keine gültige Menge.',
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
