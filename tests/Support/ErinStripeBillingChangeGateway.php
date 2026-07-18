<?php

namespace Tests\Support;

use App\Contracts\StripeBillingChangeGateway;
use App\Models\Plan;
use App\Models\PlanStripePrice;
use App\Models\StripeAddonPrice;
use App\Services\Billing\CanonicalStripePayload;
use Closure;
use RuntimeException;
use Stripe\Price;
use Stripe\StripeObject;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;

class ErinStripeBillingChangeGateway implements StripeBillingChangeGateway
{
    /** @var list<string> */
    public array $retrieveSubscriptionCalls = [];

    /** @var list<array{subscription_id: string, parameters: array<string, mixed>, idempotency_key: string}> */
    public array $updateSubscriptionCalls = [];

    /** @var list<array{subscription_id: string, idempotency_key: string}> */
    public array $createScheduleCalls = [];

    /** @var list<array{schedule_id: string, parameters: array<string, mixed>, idempotency_key: string}> */
    public array $updateScheduleCalls = [];

    /**
     * @var array<string, array{
     *     signature: string,
     *     response_type: 'subscription'|'schedule',
     *     response: array<string, mixed>
     * }>
     */
    private array $idempotentResponses = [];

    public int $subscriptionUpdateFailuresRemaining = 0;

    public int $scheduleFailuresRemaining = 0;

    public int $scheduleCreateFailuresRemaining = 0;

    public int $subscriptionMutations = 0;

    public int $scheduleMutations = 0;

    public bool $returnPendingUpdate = false;

    public bool $invoicePaymentFails = false;

    public ?Closure $beforeSubscriptionUpdate = null;

    public ?Closure $beforeRetrieveSubscription = null;

    public ?Closure $beforeRetrieveSchedule = null;

    public ?Closure $afterSubscriptionUpdate = null;

    public ?Closure $afterScheduleUpdate = null;

    public ?Closure $afterScheduleCreate = null;

    public function __construct(
        public Subscription $subscription,
        public ?SubscriptionSchedule $schedule = null,
    ) {}

    public function retrieveSubscription(string $subscriptionId): Subscription
    {
        $this->retrieveSubscriptionCalls[] = $subscriptionId;
        if ($this->beforeRetrieveSubscription instanceof Closure) {
            ($this->beforeRetrieveSubscription)(
                $this,
                count($this->retrieveSubscriptionCalls),
            );
        }
        if ($this->subscription->id !== $subscriptionId) {
            throw new RuntimeException('Unbekanntes Testabonnement.');
        }
        $this->assertSubscriptionSnapshot($this->subscription);

        return Subscription::constructFrom(
            $this->subscription->toArray(),
        );
    }

    public function retrieveSchedule(string $scheduleId): SubscriptionSchedule
    {
        if ($this->beforeRetrieveSchedule instanceof Closure) {
            ($this->beforeRetrieveSchedule)($this, $scheduleId);
        }
        if ($this->schedule?->id !== $scheduleId) {
            throw new RuntimeException('Unbekannter Testphasenplan.');
        }
        $this->assertScheduleSnapshot($this->schedule);

        return SubscriptionSchedule::constructFrom(
            $this->schedule->toArray(),
        );
    }

    public function createSchedule(
        array $parameters,
        string $idempotencyKey,
    ): SubscriptionSchedule {
        $subscriptionId = $parameters['from_subscription'] ?? null;
        if (! is_string($subscriptionId)) {
            throw new RuntimeException(
                'Der Test-Schedule benötigt ein Quellabonnement.',
            );
        }
        $this->createScheduleCalls[] = [
            'subscription_id' => $subscriptionId,
            'idempotency_key' => $idempotencyKey,
        ];
        $cached = $this->cached(
            'schedule.create',
            $subscriptionId,
            $parameters,
            $idempotencyKey,
        );
        if ($cached instanceof SubscriptionSchedule) {
            return $cached;
        }
        if ($this->scheduleCreateFailuresRemaining > 0) {
            $this->scheduleCreateFailuresRemaining--;

            throw new RuntimeException(
                'Simulierter Stripe-Schedule-Erstellungsfehler.',
            );
        }
        if ($this->subscription->id !== $subscriptionId) {
            throw new RuntimeException('Unbekanntes Testabonnement.');
        }
        $this->assertSubscriptionSnapshot($this->subscription);

        if ($this->schedule === null) {
            $baseItem = $this->baseSubscriptionItem();
            $start = $this->positiveTimestamp(
                $baseItem->current_period_start,
            );
            $end = $this->positiveTimestamp(
                $baseItem->current_period_end,
            );
            $items = array_map(
                fn (mixed $item): array => [
                    'price' => [
                        'id' => $this->externalId($item->price),
                        'object' => 'price',
                    ],
                    'quantity' => (int) $item->quantity,
                ],
                $this->subscription->items->data,
            );
            $this->schedule = SubscriptionSchedule::constructFrom([
                'id' => 'sub_sched_created',
                'object' => 'subscription_schedule',
                'customer' => $this->subscription->customer,
                'subscription' => $this->subscription->id,
                'status' => 'active',
                'end_behavior' => 'release',
                'current_phase' => [
                    'start_date' => $start,
                    'end_date' => $end,
                ],
                'phases' => [[
                    'start_date' => $start,
                    'end_date' => $end,
                    'items' => $items,
                    'proration_behavior' => 'none',
                ]],
            ]);
        }
        $this->subscription->schedule = $this->schedule->id;
        $this->remember(
            'schedule.create',
            $subscriptionId,
            $parameters,
            $idempotencyKey,
            $this->schedule,
        );
        if ($this->afterScheduleCreate instanceof Closure) {
            $callback = $this->afterScheduleCreate;
            $this->afterScheduleCreate = null;
            $callback();
        }

        return SubscriptionSchedule::constructFrom(
            $this->schedule->toArray(),
        );
    }

    public function updateSubscription(
        string $subscriptionId,
        array $parameters,
        string $idempotencyKey,
    ): Subscription {
        if ($this->beforeSubscriptionUpdate instanceof Closure) {
            ($this->beforeSubscriptionUpdate)();
        }
        $this->updateSubscriptionCalls[] = [
            'subscription_id' => $subscriptionId,
            'parameters' => $parameters,
            'idempotency_key' => $idempotencyKey,
        ];
        $cached = $this->cached(
            'subscription.update',
            $subscriptionId,
            $parameters,
            $idempotencyKey,
        );
        if ($cached instanceof Subscription) {
            return $cached;
        }
        if ($this->subscriptionUpdateFailuresRemaining > 0) {
            $this->subscriptionUpdateFailuresRemaining--;

            throw new RuntimeException('Simulierter Stripe-Upgradefehler.');
        }
        if ($this->subscription->id !== $subscriptionId) {
            throw new RuntimeException('Unbekanntes Testabonnement.');
        }
        $this->assertSubscriptionSnapshot($this->subscription);
        if (
            ($parameters['payment_behavior'] ?? null) !== 'allow_incomplete'
            || ($parameters['proration_behavior'] ?? null) !== 'always_invoice'
        ) {
            throw new RuntimeException(
                'Der Test akzeptiert nur sofortige, anteilig abgerechnete Upgrades.',
            );
        }

        $item = data_get($parameters, 'items.0');
        $itemId = is_array($item) ? ($item['id'] ?? null) : null;
        $targetPriceId = is_array($item) ? ($item['price'] ?? null) : null;
        if (! is_string($itemId) || ! is_string($targetPriceId)) {
            throw new RuntimeException(
                'Der Stripe-Upgrade-Payload ist unvollständig.',
            );
        }

        if ($this->returnPendingUpdate) {
            $this->subscription->pending_update = StripeObject::constructFrom([
                'subscription_items' => [$item],
                'expires_at' => now()->addDay()->getTimestamp(),
            ]);
        } else {
            $this->applyBasePrice($targetPriceId, $itemId);
            $this->subscription->pending_update = null;
            $this->subscriptionMutations++;
            $this->applyInvoiceOutcome();
        }
        $this->remember(
            'subscription.update',
            $subscriptionId,
            $parameters,
            $idempotencyKey,
            $this->subscription,
        );
        if ($this->afterSubscriptionUpdate instanceof Closure) {
            $callback = $this->afterSubscriptionUpdate;
            $this->afterSubscriptionUpdate = null;
            $callback();
        }

        return Subscription::constructFrom(
            $this->subscription->toArray(),
        );
    }

    public function updateSchedule(
        string $scheduleId,
        array $parameters,
        string $idempotencyKey,
    ): SubscriptionSchedule {
        $this->updateScheduleCalls[] = [
            'schedule_id' => $scheduleId,
            'parameters' => $parameters,
            'idempotency_key' => $idempotencyKey,
        ];
        $cached = $this->cached(
            'schedule.update',
            $scheduleId,
            $parameters,
            $idempotencyKey,
        );
        if ($cached instanceof SubscriptionSchedule) {
            return $cached;
        }
        if ($this->scheduleFailuresRemaining > 0) {
            $this->scheduleFailuresRemaining--;

            throw new RuntimeException('Simulierter Stripe-Schedulefehler.');
        }
        if ($this->schedule === null || $this->schedule->id !== $scheduleId) {
            throw new RuntimeException('Unbekannter Testphasenplan.');
        }
        $this->assertScheduleSnapshot($this->schedule);
        $phases = $parameters['phases'] ?? null;
        $endBehavior = $parameters['end_behavior'] ?? null;
        if (
            ! is_array($phases)
            || ! is_string($endBehavior)
            || ! in_array($endBehavior, ['cancel', 'release'], true)
        ) {
            throw new RuntimeException(
                'Der Stripe-Schedule-Payload ist unvollständig.',
            );
        }

        $this->schedule->phases = $phases;
        $this->schedule->end_behavior = $endBehavior;
        $this->schedule->default_settings = $parameters[
            'default_settings'
        ] ?? data_get($this->schedule->toArray(), 'default_settings');
        $this->assertCanonicalPhases($phases);
        $firstStart = data_get($phases, '0.start_date');
        $firstEnd = data_get($phases, '0.end_date');
        $this->schedule->current_phase = StripeObject::constructFrom([
            'start_date' => $firstStart,
            'end_date' => $firstEnd,
        ]);
        $currentTargetPrice = data_get($phases, '0.items.0.price');
        if (! is_string($currentTargetPrice)) {
            throw new RuntimeException(
                'Die aktuelle Testphase enthält keinen Basistarif.',
            );
        }
        $currentBase = $this->externalId($this->baseSubscriptionItem()->price);
        if ($currentTargetPrice !== $currentBase) {
            $this->applyBasePrice($currentTargetPrice);
            $this->subscriptionMutations++;
            $this->applyInvoiceOutcome();
        }
        $this->scheduleMutations++;
        $this->remember(
            'schedule.update',
            $scheduleId,
            $parameters,
            $idempotencyKey,
            $this->schedule,
        );
        if ($this->afterScheduleUpdate instanceof Closure) {
            $callback = $this->afterScheduleUpdate;
            $this->afterScheduleUpdate = null;
            $callback();
        }

        return SubscriptionSchedule::constructFrom(
            $this->schedule->toArray(),
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function cached(
        string $method,
        string $targetId,
        array $parameters,
        string $idempotencyKey,
    ): Subscription|SubscriptionSchedule|null {
        $stored = $this->idempotentResponses[$idempotencyKey] ?? null;
        if ($stored === null) {
            return null;
        }
        $signature = $this->signature($method, $targetId, $parameters);
        if ($stored['signature'] !== $signature) {
            throw new RuntimeException(
                'Ein Stripe-Idempotency-Key wurde mit abweichendem Payload wiederverwendet.',
            );
        }

        return $stored['response_type'] === 'subscription'
            ? Subscription::constructFrom($stored['response'])
            : SubscriptionSchedule::constructFrom($stored['response']);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function remember(
        string $method,
        string $targetId,
        array $parameters,
        string $idempotencyKey,
        Subscription|SubscriptionSchedule $response,
    ): void {
        $this->idempotentResponses[$idempotencyKey] = [
            'signature' => $this->signature(
                $method,
                $targetId,
                $parameters,
            ),
            'response_type' => $response instanceof Subscription
                ? 'subscription'
                : 'schedule',
            'response' => $response->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function signature(
        string $method,
        string $targetId,
        array $parameters,
    ): string {
        $canonical = new CanonicalStripePayload;

        return hash('sha256', implode('|', [
            $method,
            $targetId,
            $canonical->hash($parameters),
        ]));
    }

    private function baseSubscriptionItem(): mixed
    {
        $knownPrices = PlanStripePrice::query()
            ->pluck('stripe_price_id')
            ->merge(Plan::query()->pluck('stripe_price_id'))
            ->filter()
            ->flip();
        foreach ($this->subscription->items->data as $item) {
            $priceId = $this->externalId($item->price);
            if ($priceId !== null && $knownPrices->has($priceId)) {
                return $item;
            }
        }

        throw new RuntimeException(
            'Das Testabonnement enthält kein Basispaket.',
        );
    }

    private function applyBasePrice(
        string $targetPriceId,
        ?string $expectedItemId = null,
    ): void {
        $productId = PlanStripePrice::query()
            ->where('stripe_price_id', $targetPriceId)
            ->value('stripe_product_id')
            ?? Plan::query()
                ->where('stripe_price_id', $targetPriceId)
                ->value('stripe_product_id');
        if (! is_string($productId)) {
            throw new RuntimeException('Unbekanntes Testprodukt.');
        }

        $item = $this->baseSubscriptionItem();
        if ($expectedItemId !== null && $item->id !== $expectedItemId) {
            throw new RuntimeException('Unbekannte Testposition.');
        }
        $item->price = Price::constructFrom([
            'id' => $targetPriceId,
            'object' => 'price',
            'product' => $productId,
        ]);
        $item->quantity = 1;
    }

    private function applyInvoiceOutcome(): void
    {
        if ($this->invoicePaymentFails) {
            $this->subscription->status = 'past_due';
            $this->subscription->latest_invoice = StripeObject::constructFrom([
                'id' => 'in_test_proration_failed',
                'object' => 'invoice',
                'status' => 'open',
                'amount_remaining' => 150_000,
            ]);

            return;
        }

        $this->subscription->latest_invoice = StripeObject::constructFrom([
            'id' => 'in_test_proration_paid',
            'object' => 'invoice',
            'status' => 'paid',
            'amount_remaining' => 0,
        ]);
    }

    private function assertSubscriptionSnapshot(
        Subscription $subscription,
    ): void {
        $customerId = $this->externalId($subscription->customer);
        $status = data_get($subscription->toArray(), 'status');
        if (
            ! is_string($subscription->id)
            || ! str_starts_with($subscription->id, 'sub_')
            || $customerId === null
            || ! str_starts_with($customerId, 'cus_')
            || ! in_array(
                $status,
                ['active', 'trialing', 'past_due'],
                true,
            )
        ) {
            throw new RuntimeException(
                'Das Testabonnement besitzt keine kanonische Identität oder keinen unterstützten Status.',
            );
        }
        $this->baseSubscriptionItem();
    }

    private function assertScheduleSnapshot(
        SubscriptionSchedule $schedule,
    ): void {
        $snapshot = $schedule->toArray();
        if (
            data_get($snapshot, 'status') !== 'active'
            || $this->externalId($snapshot['customer'] ?? null)
                !== $this->externalId($this->subscription->customer)
            || $this->externalId($snapshot['subscription'] ?? null)
                !== $this->subscription->id
            || $this->externalId(
                data_get($this->subscription->toArray(), 'schedule'),
            ) !== $schedule->id
        ) {
            throw new RuntimeException(
                'Der Test-Schedule besitzt keine aktive kanonische Subscription- und Kundenbindung.',
            );
        }

        $phases = $snapshot['phases'] ?? null;
        if (! is_array($phases)) {
            throw new RuntimeException(
                'Der Test-Schedule besitzt keinen kanonischen Phasenplan.',
            );
        }
        $this->assertCanonicalPhases(array_values($phases));
    }

    /**
     * @param  list<mixed>  $phases
     */
    private function assertCanonicalPhases(array $phases): void
    {
        if ($phases === []) {
            throw new RuntimeException(
                'Der Test-Schedule besitzt keine Phase.',
            );
        }

        $knownBasePrices = PlanStripePrice::query()
            ->pluck('stripe_price_id')
            ->merge(Plan::query()->pluck('stripe_price_id'))
            ->filter()
            ->flip();
        $knownAddOnPrices = StripeAddonPrice::query()
            ->pluck('stripe_price_id')
            ->merge(collect([
                config('services.stripe.seat_price_id'),
                config('services.stripe.visa_price_id'),
            ]))
            ->filter()
            ->flip();
        $previousEnd = null;

        foreach ($phases as $phase) {
            $phase = $phase instanceof StripeObject
                ? $phase->toArray()
                : $phase;
            $start = is_array($phase)
                ? ($phase['start_date'] ?? null)
                : null;
            $end = is_array($phase)
                ? ($phase['end_date'] ?? null)
                : null;
            $items = is_array($phase)
                ? ($phase['items'] ?? null)
                : null;
            if (
                ! is_int($start)
                || ! is_int($end)
                || $start < 1
                || $end <= $start
                || ($previousEnd !== null && $start !== $previousEnd)
                || ! is_array($items)
                || $items === []
            ) {
                throw new RuntimeException(
                    'Der Test-Schedule besitzt keine lückenlosen kanonischen Phasengrenzen.',
                );
            }

            $baseCount = 0;
            $seen = [];
            foreach ($items as $item) {
                $priceId = $this->externalId(data_get($item, 'price'));
                $quantity = data_get($item, 'quantity');
                if (
                    $priceId === null
                    || isset($seen[$priceId])
                    || ! is_int($quantity)
                    || $quantity < 1
                ) {
                    throw new RuntimeException(
                        'Eine Test-Schedule-Position ist nicht kanonisch.',
                    );
                }
                $seen[$priceId] = true;
                if ($knownBasePrices->has($priceId)) {
                    if ($quantity !== 1) {
                        throw new RuntimeException(
                            'Die Test-Basis-Price besitzt nicht die Menge 1.',
                        );
                    }
                    $baseCount++;
                } elseif (! $knownAddOnPrices->has($priceId)) {
                    throw new RuntimeException(
                        'Eine Test-Schedule-Position besitzt keine bekannte Preisrolle.',
                    );
                }
            }
            if ($baseCount !== 1) {
                throw new RuntimeException(
                    'Eine Test-Schedule-Phase besitzt nicht genau einen Basistarif.',
                );
            }
            $previousEnd = $end;
        }
    }

    private function positiveTimestamp(mixed $value): int
    {
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException(
                'Das Testabonnement enthält keinen gültigen Zeitraum.',
            );
        }

        return $value;
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
