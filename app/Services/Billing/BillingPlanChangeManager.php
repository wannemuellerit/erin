<?php

namespace App\Services\Billing;

use App\Contracts\StripeBillingChangeGateway;
use App\Models\BillingChangeIntent;
use App\Models\Company;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;
use Throwable;

class BillingPlanChangeManager
{
    /** @var list<string> */
    private const TERMINAL_STATUSES = [
        'applied',
        'manual_review',
        'closed',
    ];

    private const RETRYABLE_ERROR = 'Die Stripe-Änderung muss sicher abgeglichen werden; technische Details stehen ausschließlich im geschützten Anwendungslog.';

    private const MANUAL_REVIEW_ERROR = 'Die Stripe-Änderung wurde extern verändert und muss manuell geprüft werden; technische Details stehen ausschließlich im geschützten Anwendungslog.';

    public function __construct(
        private readonly StripeBillingChangeGateway $gateway,
        private readonly StripeSubscriptionItemClassifier $items,
        private readonly StripeSubscriptionScheduleBuilder $schedules,
        private readonly SubscriptionChangePolicy $policy,
        private readonly PlanStripePriceRegistry $prices,
        private readonly CanonicalStripePayload $canonicalPayload,
    ) {}

    public function request(
        Company $company,
        Plan $currentPlan,
        Plan $targetPlan,
        ?int $requestedBy,
        CarbonInterface $effectiveAt,
    ): BillingChangeIntent {
        $isUpgrade = $this->policy->isUpgrade($currentPlan, $targetPlan);
        $this->prices->record($currentPlan, 'billing_change');
        $this->prices->record($targetPlan, 'billing_change');
        $targetPriceId = $this->requiredPrice($targetPlan);
        $publicId = (string) Str::uuid();
        $activeCompanyKey = 'company:'.$company->getKey();

        try {
            $intent = Cache::lock($this->lockKey($company), 120)
                ->block(15, function () use (
                    $company,
                    $currentPlan,
                    $targetPlan,
                    $requestedBy,
                    $effectiveAt,
                    $isUpgrade,
                    $publicId,
                    $activeCompanyKey,
                    $targetPriceId,
                ): BillingChangeIntent {
                    /** @var Company $companySnapshot */
                    $companySnapshot = Company::query()->findOrFail(
                        $company->getKey(),
                    );
                    if (
                        $companySnapshot->current_plan_id
                            !== $currentPlan->getKey()
                        || ! is_string(
                            $companySnapshot->stripe_subscription_id,
                        )
                        || $companySnapshot->stripe_subscription_id === ''
                    ) {
                        throw new DomainException(
                            'Das aktive Firmenabonnement hat sich parallel geändert.',
                        );
                    }

                    $canonical = $this->gateway->retrieveSubscription(
                        $companySnapshot->stripe_subscription_id,
                    );
                    $this->assertCompanySubscriptionIdentity(
                        $companySnapshot,
                        $canonical,
                    );
                    $this->assertNoPendingUpdate($canonical);
                    $classification = $this->items->classify(
                        $canonical->items->data,
                    );
                    $canonicalPlan = $classification['base_plan'];
                    $canonicalBaseItem = $classification['base_item'];
                    if (
                        ! $canonicalPlan instanceof Plan
                        || ! is_array($canonicalBaseItem)
                        || ! in_array($canonicalPlan->getKey(), [
                            $currentPlan->getKey(),
                            $targetPlan->getKey(),
                        ], true)
                    ) {
                        throw new DomainException(
                            'Das kanonische Stripe-Basispaket stimmt nicht mit dem angeforderten Tarifwechsel überein.',
                        );
                    }
                    try {
                        $canonicalSchedule = null;
                        $canonicalScheduleId = $this->externalId(
                            data_get(
                                $canonical->toArray(),
                                'schedule',
                            ),
                        );
                        if ($canonicalScheduleId !== null) {
                            $canonicalSchedule = $this->gateway
                                ->retrieveSchedule(
                                    $canonicalScheduleId,
                                );
                            $this->assertCompanyScheduleIdentity(
                                $companySnapshot,
                                $canonical,
                                $canonicalSchedule,
                            );
                        }
                    } catch (Throwable $exception) {
                        throw new DomainException(
                            'Der Tarifwechsel wurde abgelehnt, weil der Stripe-Schedule nicht eindeutig an Firma und Abonnement gebunden ist.',
                            previous: $exception,
                        );
                    }
                    if (! $isUpgrade) {
                        try {
                            $this->assertDowngradeCancellationSafe(
                                $canonical,
                                $canonicalSchedule,
                            );
                        } catch (Throwable $exception) {
                            throw new DomainException(
                                'Der Downgrade wurde abgelehnt, weil ein bestehender Kündigungs- oder Schedule-Zustand nicht sicher erhalten werden kann.',
                                previous: $exception,
                            );
                        }
                    }

                    return DB::transaction(function () use (
                        $company,
                        $currentPlan,
                        $targetPlan,
                        $requestedBy,
                        $effectiveAt,
                        $isUpgrade,
                        $publicId,
                        $activeCompanyKey,
                        $canonical,
                        $canonicalBaseItem,
                        $targetPriceId,
                    ): BillingChangeIntent {
                        /** @var Company $lockedCompany */
                        $lockedCompany = Company::query()
                            ->lockForUpdate()
                            ->findOrFail($company->getKey());
                        if (
                            $lockedCompany->current_plan_id !== $currentPlan->getKey()
                            || ! is_string($lockedCompany->stripe_subscription_id)
                            || $lockedCompany->stripe_subscription_id === ''
                        ) {
                            throw new DomainException(
                                'Das aktive Firmenabonnement hat sich parallel geändert.',
                            );
                        }

                        /** @var BillingChangeIntent|null $existing */
                        $existing = BillingChangeIntent::query()
                            ->where('active_company_key', $activeCompanyKey)
                            ->lockForUpdate()
                            ->first();
                        if ($existing !== null) {
                            if (
                                $existing->to_plan_id === $targetPlan->getKey()
                                && $existing->change_type === (
                                    $isUpgrade ? 'upgrade' : 'downgrade'
                                )
                            ) {
                                return $existing;
                            }

                            throw new DomainException(
                                'Für dieses Unternehmen wird bereits eine andere Abrechnungsänderung verarbeitet.',
                            );
                        }

                        /** @var Plan $lockedTargetPlan */
                        $lockedTargetPlan = Plan::query()
                            ->lockForUpdate()
                            ->findOrFail($targetPlan->getKey());
                        if (
                            $this->requiredPrice($lockedTargetPlan)
                                !== $targetPriceId
                        ) {
                            throw new DomainException(
                                'Der Stripe-Zielpreis hat sich parallel geändert.',
                            );
                        }

                        return BillingChangeIntent::query()->create([
                            'public_id' => $publicId,
                            'company_id' => $lockedCompany->getKey(),
                            'from_plan_id' => $currentPlan->getKey(),
                            'to_plan_id' => $targetPlan->getKey(),
                            'requested_by' => $requestedBy,
                            'change_type' => $isUpgrade
                                ? 'upgrade'
                                : 'downgrade',
                            'status' => 'pending',
                            'active_company_key' => $activeCompanyKey,
                            'stripe_subscription_id' => $lockedCompany
                                ->stripe_subscription_id,
                            'from_stripe_price_id' => $canonicalBaseItem[
                                'price'
                            ],
                            'to_stripe_price_id' => $targetPriceId,
                            'stripe_idempotency_key' => 'erin-billing-'.$publicId,
                            'context' => [
                                'source_subscription_item' => [
                                    'id' => $canonicalBaseItem['id'],
                                    'price' => $canonicalBaseItem['price'],
                                    'product' => $canonicalBaseItem['product'],
                                ],
                                'source_subscription_snapshot_id' => $canonical
                                    ->id,
                                'remote_operations' => [],
                            ],
                            'attempts' => 0,
                            'effective_at' => $effectiveAt,
                        ]);
                    }, 3);
                });
        } catch (LockTimeoutException $exception) {
            throw new DomainException(
                'Für dieses Unternehmen wird bereits eine Abrechnungsänderung verarbeitet.',
                previous: $exception,
            );
        }

        return $this->reconcile($intent);
    }

    public function reconcile(
        BillingChangeIntent $intent,
    ): BillingChangeIntent {
        if (in_array($intent->status, self::TERMINAL_STATUSES, true)) {
            return $intent;
        }

        try {
            return Cache::lock(
                $this->lockKey($intent->company),
                120,
            )->block(15, function () use ($intent): BillingChangeIntent {
                $intent = DB::transaction(
                    function () use ($intent): BillingChangeIntent {
                        /** @var BillingChangeIntent $locked */
                        $locked = BillingChangeIntent::query()
                            ->lockForUpdate()
                            ->findOrFail($intent->getKey());
                        if (in_array(
                            $locked->status,
                            self::TERMINAL_STATUSES,
                            true,
                        )) {
                            return $locked;
                        }
                        $locked->forceFill([
                            'status' => 'applying',
                            'attempts' => $locked->attempts + 1,
                            'last_error' => null,
                        ])->save();

                        return $locked;
                    },
                    3,
                );

                try {
                    $result = $this->applyRemote($intent);

                    return $this->finalize(
                        $intent,
                        $result['effective_at'],
                        $result['target_is_current'],
                    );
                } catch (BillingManualReviewRequired $exception) {
                    report($exception);
                    $this->markForManualReview($intent);

                    return $intent->refresh();
                } catch (Throwable $exception) {
                    report($exception);
                    $this->markForReconciliation($intent);

                    return $intent->refresh();
                }
            });
        } catch (LockTimeoutException $exception) {
            throw new DomainException(
                'Für dieses Unternehmen läuft bereits ein Stripe-Abgleich.',
                previous: $exception,
            );
        }
    }

    /**
     * @return array{
     *     effective_at: CarbonInterface,
     *     target_is_current: bool
     * }
     */
    private function applyRemote(BillingChangeIntent $intent): array
    {
        $subscription = $this->gateway->retrieveSubscription(
            $intent->stripe_subscription_id,
        );
        $this->assertSubscriptionIdentity($intent, $subscription);
        $this->assertNoPendingUpdate($subscription);

        $classification = $this->items->classify(
            $subscription->items->data,
        );
        $basePlan = $classification['base_plan'];
        $baseItem = $classification['base_item'];
        if (! $basePlan instanceof Plan || ! is_array($baseItem)) {
            throw new RuntimeException(
                'Das Stripe-Abonnement enthält kein eindeutiges Basispaket.',
            );
        }

        if (
            $basePlan->getKey() === $intent->to_plan_id
            && $baseItem['price'] === $intent->to_stripe_price_id
        ) {
            $this->assertPersistedScheduleBeforeTargetFinalization(
                $intent,
                $subscription,
            );

            return [
                'effective_at' => now(),
                'target_is_current' => true,
            ];
        }
        if (
            $basePlan->getKey() !== $intent->from_plan_id
            || $baseItem['price'] !== $intent->from_stripe_price_id
        ) {
            throw new RuntimeException(
                'Das Stripe-Basispaket entspricht keiner im Billing-Intent fixierten Price.',
            );
        }
        $this->assertIntentSourceItem($intent, $baseItem);

        if ($intent->change_type === 'upgrade') {
            return $this->applyUpgrade($intent, $subscription, $baseItem);
        }
        if ($intent->change_type === 'downgrade') {
            return $this->applyDowngrade($intent, $subscription);
        }

        throw new RuntimeException(
            'Der Billing-Intent enthält keinen unterstützten Änderungstyp.',
        );
    }

    /**
     * @param  array{id: string, price: string}  $baseItem
     * @return array{
     *     effective_at: CarbonInterface,
     *     target_is_current: bool
     * }
     */
    private function applyUpgrade(
        BillingChangeIntent $intent,
        Subscription $subscription,
        array $baseItem,
    ): array {
        $this->assertProrationSafe($subscription);
        $scheduleId = $this->externalId(
            data_get($subscription->toArray(), 'schedule'),
        );
        $persistedScheduleId = $this->remoteOperationTargetId(
            $intent,
            'schedule_upgrade',
            'subscription_schedule',
        );
        if (
            $persistedScheduleId !== null
            && $scheduleId !== $persistedScheduleId
        ) {
            throw new BillingManualReviewRequired(
                'Der Stripe-Schedule wurde nach dem Upgrade-Aufruf extern gelöst oder ersetzt.',
            );
        }

        if ($scheduleId === null) {
            $idempotencyKey = $intent->stripe_idempotency_key.'-upgrade';
            $parameters = $this->preparedRemoteOperation(
                $intent,
                'subscription_upgrade',
                'subscription',
                $subscription->id,
                $idempotencyKey,
                fn (): array => [
                    'expand' => [
                        'items.data.price.product',
                        'latest_invoice',
                    ],
                    'items' => [[
                        'id' => $baseItem['id'],
                        'price' => $intent->to_stripe_price_id,
                        'quantity' => 1,
                    ]],
                    'payment_behavior' => 'allow_incomplete',
                    'proration_behavior' => 'always_invoice',
                ],
            );
            $updated = $this->gateway->updateSubscription(
                $subscription->id,
                $parameters,
                $idempotencyKey,
            );
            $this->assertSubscriptionIdentity($intent, $updated);
            $this->assertNoPendingUpdate($updated);
            $this->assertTargetBasePrice($intent, $updated);
        } else {
            try {
                $schedule = $this->gateway->retrieveSchedule($scheduleId);
            } catch (Throwable $exception) {
                if ($persistedScheduleId !== null) {
                    throw new BillingManualReviewRequired(
                        'Der Stripe-Schedule kann nach dem Upgrade-Aufruf nicht mehr kanonisch abgerufen werden.',
                        previous: $exception,
                    );
                }

                throw $exception;
            }
            $this->assertScheduleIdentity(
                $intent,
                $subscription,
                $schedule,
            );
            $idempotencyKey = $intent->stripe_idempotency_key
                .'-schedule-upgrade';
            $parameters = $this->preparedRemoteOperation(
                $intent,
                'schedule_upgrade',
                'subscription_schedule',
                $schedule->id,
                $idempotencyKey,
                fn (): array => $this->schedules->upgradeParameters(
                    $subscription,
                    $schedule,
                    $intent->from_stripe_price_id,
                    $intent->to_stripe_price_id,
                ),
            );
            $updated = $this->gateway->updateSchedule(
                $schedule->id,
                $parameters,
                $idempotencyKey,
            );
            if ($updated->id !== $schedule->id) {
                throw new BillingManualReviewRequired(
                    'Stripe hat einen unpassenden Schedule aktualisiert.',
                );
            }
        }

        $canonical = $this->gateway->retrieveSubscription(
            $intent->stripe_subscription_id,
        );
        $this->assertSubscriptionIdentity($intent, $canonical);
        $this->assertNoPendingUpdate($canonical);
        $this->assertTargetBasePrice($intent, $canonical);
        if ($scheduleId !== null) {
            $canonicalSchedule = $this->gateway->retrieveSchedule(
                $scheduleId,
            );
            $this->assertCanonicalScheduleAfterMutation(
                $intent,
                $canonical,
                $canonicalSchedule,
                $parameters,
                'upgrade',
            );
        }

        return [
            'effective_at' => now(),
            'target_is_current' => true,
        ];
    }

    /**
     * @return array{
     *     effective_at: CarbonInterface,
     *     target_is_current: bool
     * }
     */
    private function applyDowngrade(
        BillingChangeIntent $intent,
        Subscription $subscription,
    ): array {
        $this->assertNoPendingUpdate($subscription);
        $this->assertDowngradeCancellationSafeForIntent($subscription);
        $scheduleId = $this->externalId(
            data_get($subscription->toArray(), 'schedule'),
        );
        $persistedScheduleId = $this->remoteOperationTargetId(
            $intent,
            'schedule_downgrade',
            'subscription_schedule',
        );
        if (
            $persistedScheduleId !== null
            && $scheduleId !== $persistedScheduleId
        ) {
            throw new BillingManualReviewRequired(
                'Der Stripe-Schedule wurde nach dem Downgrade-Aufruf extern gelöst oder ersetzt.',
            );
        }

        if ($scheduleId === null) {
            $idempotencyKey = $intent->stripe_idempotency_key
                .'-schedule-create';
            $parameters = $this->preparedRemoteOperation(
                $intent,
                'schedule_create',
                'subscription',
                $subscription->id,
                $idempotencyKey,
                fn (): array => [
                    'expand' => ['phases.items.price'],
                    'from_subscription' => $subscription->id,
                ],
            );
            $created = $this->gateway->createSchedule(
                $parameters,
                $idempotencyKey,
            );
            $scheduleId = $this->requiredExternalId(
                $created,
                'Stripe-Schedule',
            );
            $this->mergeContext($intent, [
                'stripe_schedule_id' => $scheduleId,
            ]);

            $subscription = $this->gateway->retrieveSubscription(
                $intent->stripe_subscription_id,
            );
            $this->assertSubscriptionIdentity($intent, $subscription);
            $this->assertNoPendingUpdate($subscription);
            $attachedScheduleId = $this->externalId(
                data_get($subscription->toArray(), 'schedule'),
            );
            if ($attachedScheduleId !== $scheduleId) {
                throw new BillingManualReviewRequired(
                    'Der erstellte Stripe-Schedule wurde nicht eindeutig an das Abonnement gebunden.',
                );
            }
        }

        try {
            $schedule = $this->gateway->retrieveSchedule($scheduleId);
        } catch (Throwable $exception) {
            if ($persistedScheduleId !== null) {
                throw new BillingManualReviewRequired(
                    'Der Stripe-Schedule kann nach dem Downgrade-Aufruf nicht mehr kanonisch abgerufen werden.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
        $this->assertScheduleIdentity(
            $intent,
            $subscription,
            $schedule,
        );
        $this->assertDowngradeCancellationSafeForIntent(
            $subscription,
            $schedule,
        );
        $idempotencyKey = $intent->stripe_idempotency_key
            .'-schedule-downgrade';
        $parameters = $this->preparedRemoteOperation(
            $intent,
            'schedule_downgrade',
            'subscription_schedule',
            $schedule->id,
            $idempotencyKey,
            fn (): array => $this->schedules->downgradeParameters(
                $subscription,
                $schedule,
                $intent->from_stripe_price_id,
                $intent->to_stripe_price_id,
            ),
        );
        $updated = $this->gateway->updateSchedule(
            $schedule->id,
            $parameters,
            $idempotencyKey,
        );
        if ($updated->id !== $schedule->id) {
            throw new BillingManualReviewRequired(
                'Stripe hat einen unpassenden Schedule aktualisiert.',
            );
        }

        $canonicalSubscription = $this->gateway->retrieveSubscription(
            $intent->stripe_subscription_id,
        );
        $this->assertSubscriptionIdentity($intent, $canonicalSubscription);
        $this->assertNoPendingUpdate($canonicalSubscription);
        $canonicalSchedule = $this->gateway->retrieveSchedule(
            $schedule->id,
        );
        $effectiveAt = $this->assertCanonicalScheduleAfterMutation(
            $intent,
            $canonicalSubscription,
            $canonicalSchedule,
            $parameters,
            'downgrade',
        );
        $this->mergeContext($intent, [
            'stripe_schedule_id' => $schedule->id,
        ]);

        return [
            'effective_at' => CarbonImmutable::createFromTimestamp(
                $effectiveAt,
                config('app.timezone'),
            ),
            'target_is_current' => false,
        ];
    }

    private function assertPersistedScheduleBeforeTargetFinalization(
        BillingChangeIntent $intent,
        Subscription $subscription,
    ): void {
        $operation = match ($intent->change_type) {
            'upgrade' => 'schedule_upgrade',
            'downgrade' => 'schedule_downgrade',
            default => null,
        };
        if ($operation === null) {
            return;
        }

        $scheduleId = $this->remoteOperationTargetId(
            $intent,
            $operation,
            'subscription_schedule',
        );
        if ($scheduleId === null) {
            return;
        }

        try {
            if (
                $this->externalId(
                    data_get($subscription->toArray(), 'schedule'),
                ) !== $scheduleId
            ) {
                throw new RuntimeException(
                    'Der persistierte Stripe-Schedule ist nicht mehr am Abonnement aktiv.',
                );
            }

            $idempotencyKey = $intent->stripe_idempotency_key
                .($intent->change_type === 'upgrade'
                    ? '-schedule-upgrade'
                    : '-schedule-downgrade');
            $parameters = $this->preparedRemoteOperation(
                $intent,
                $operation,
                'subscription_schedule',
                $scheduleId,
                $idempotencyKey,
                fn (): array => throw new RuntimeException(
                    'Die persistierte Stripe-Schedule-Operation fehlt.',
                ),
            );
            $schedule = $this->gateway->retrieveSchedule($scheduleId);
            $this->assertCanonicalScheduleAfterMutation(
                $intent,
                $subscription,
                $schedule,
                $parameters,
                $intent->change_type,
            );
        } catch (BillingManualReviewRequired $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BillingManualReviewRequired(
                'Der bereits ausgeführte Stripe-Schedule konnte vor dem lokalen Abschluss nicht erneut bestätigt werden.',
                previous: $exception,
            );
        }
    }

    private function assertProrationSafe(Subscription $subscription): void
    {
        $this->assertNoPendingUpdate($subscription);
        $invoice = data_get($subscription->toArray(), 'latest_invoice');
        $invoiceStatus = data_get($invoice, 'status');
        $remaining = data_get($invoice, 'amount_remaining');
        $hasUnpaidInvoice = is_string($invoiceStatus)
            && $invoiceStatus !== 'paid'
            && is_int($remaining)
            && $remaining > 0;
        $subscriptionNeedsEvidence = in_array(
            data_get($subscription->toArray(), 'status'),
            ['incomplete', 'past_due', 'unpaid'],
            true,
        );
        $invoiceIsSettled = $invoiceStatus === 'paid'
            || (is_int($remaining) && $remaining === 0);

        if (
            $hasUnpaidInvoice
            || ($subscriptionNeedsEvidence && ! $invoiceIsSettled)
        ) {
            throw new RuntimeException(
                'Ein sofortiger Tarifwechsel ist erst nach Klärung der offenen Stripe-Rechnung proration-sicher möglich.',
            );
        }
    }

    private function assertNoPendingUpdate(Subscription $subscription): void
    {
        if (data_get($subscription->toArray(), 'pending_update') !== null) {
            throw new RuntimeException(
                'Stripe hat den Tarifwechsel nur als ausstehendes Update vorgemerkt.',
            );
        }
    }

    /**
     * @param  array{
     *     id: string,
     *     price: string,
     *     product: string
     * }  $baseItem
     */
    private function assertIntentSourceItem(
        BillingChangeIntent $intent,
        array $baseItem,
    ): void {
        $source = data_get($intent->context, 'source_subscription_item');
        if (
            ! is_array($source)
            || ($source['id'] ?? null) !== $baseItem['id']
            || ($source['price'] ?? null) !== $baseItem['price']
            || ($source['product'] ?? null) !== $baseItem['product']
        ) {
            throw new BillingManualReviewRequired(
                'Die kanonische Stripe-Abonnementposition hat sich seit der Intent-Erstellung verändert.',
            );
        }
    }

    private function assertDowngradeCancellationSafe(
        Subscription $subscription,
        ?SubscriptionSchedule $schedule = null,
    ): void {
        $snapshot = $subscription->toArray();
        $cancelAt = data_get($snapshot, 'cancel_at');
        if (
            data_get($snapshot, 'cancel_at_period_end') === true
            || (is_int($cancelAt) && $cancelAt > 0)
        ) {
            throw new RuntimeException(
                'Ein Downgrade darf einen bereits festgelegten Kündigungstermin nicht verändern.',
            );
        }

        if (
            $schedule !== null
            && data_get($schedule->toArray(), 'end_behavior') === 'cancel'
        ) {
            throw new RuntimeException(
                'Ein Downgrade auf einem kündigenden Stripe-Schedule wird aus Sicherheitsgründen nicht automatisch ausgeführt.',
            );
        }
    }

    private function assertDowngradeCancellationSafeForIntent(
        Subscription $subscription,
        ?SubscriptionSchedule $schedule = null,
    ): void {
        try {
            $this->assertDowngradeCancellationSafe(
                $subscription,
                $schedule,
            );
        } catch (Throwable $exception) {
            throw new BillingManualReviewRequired(
                'Der Stripe-Kündigungszustand hat sich nach der Downgrade-Anforderung verändert.',
                previous: $exception,
            );
        }
    }

    private function assertTargetBasePrice(
        BillingChangeIntent $intent,
        Subscription $subscription,
    ): void {
        $classification = $this->items->classify(
            $subscription->items->data,
        );
        $plan = $classification['base_plan'];
        $item = $classification['base_item'];
        if (
            ! $plan instanceof Plan
            || ! is_array($item)
            || $plan->getKey() !== $intent->to_plan_id
            || $item['price'] !== $intent->to_stripe_price_id
        ) {
            throw new RuntimeException(
                'Stripe hat den Zielpreis nicht kanonisch als Basispaket bestätigt.',
            );
        }
    }

    private function assertSubscriptionIdentity(
        BillingChangeIntent $intent,
        Subscription $subscription,
    ): void {
        $this->assertCompanySubscriptionIdentity(
            $intent->company,
            $subscription,
        );
        if (
            $subscription->id !== $intent->stripe_subscription_id
        ) {
            throw new RuntimeException(
                'Stripe hat ein unpassendes Firmenabonnement zurückgegeben.',
            );
        }
    }

    private function assertCompanySubscriptionIdentity(
        Company $company,
        Subscription $subscription,
    ): void {
        $status = data_get($subscription->toArray(), 'status');
        if (
            $subscription->id !== $company->stripe_subscription_id
            || $this->externalId($subscription->customer)
                !== $company->stripe_id
            || ! is_string($status)
            || ! in_array(
                $status,
                ['active', 'trialing', 'past_due'],
                true,
            )
        ) {
            throw new RuntimeException(
                'Stripe hat kein aktives, eindeutig gebundenes Firmenabonnement zurückgegeben.',
            );
        }
    }

    private function assertScheduleIdentity(
        BillingChangeIntent $intent,
        Subscription $subscription,
        SubscriptionSchedule $schedule,
    ): void {
        $scheduleSnapshot = $schedule->toArray();
        $scheduleSubscription = $this->externalId(
            $scheduleSnapshot['subscription'] ?? null,
        );
        $scheduleCustomer = $this->externalId(
            $scheduleSnapshot['customer'] ?? null,
        );
        if (
            $scheduleSubscription !== $subscription->id
            || $scheduleCustomer !== $intent->company->stripe_id
            || $this->externalId(
                data_get($subscription->toArray(), 'schedule'),
            ) !== $schedule->id
            || data_get($scheduleSnapshot, 'status') !== 'active'
        ) {
            throw new RuntimeException(
                'Stripe hat keinen aktiven, eindeutig gebundenen Schedule zurückgegeben.',
            );
        }
    }

    private function assertCompanyScheduleIdentity(
        Company $company,
        Subscription $subscription,
        SubscriptionSchedule $schedule,
    ): void {
        $snapshot = $schedule->toArray();
        if (
            $this->externalId($snapshot['subscription'] ?? null)
                !== $subscription->id
            || $this->externalId($snapshot['customer'] ?? null)
                !== $company->stripe_id
            || $this->externalId(
                data_get($subscription->toArray(), 'schedule'),
            ) !== $schedule->id
            || data_get($snapshot, 'status') !== 'active'
        ) {
            throw new RuntimeException(
                'Stripe hat keinen aktiven, eindeutig gebundenen Schedule zurückgegeben.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function assertCanonicalScheduleAfterMutation(
        BillingChangeIntent $intent,
        Subscription $subscription,
        SubscriptionSchedule $schedule,
        array $parameters,
        string $changeType,
    ): int {
        try {
            $this->assertScheduleIdentity(
                $intent,
                $subscription,
                $schedule,
            );

            return $this->schedules->assertCanonicalUpdate(
                $subscription,
                $schedule,
                $parameters,
                $intent->from_stripe_price_id,
                $intent->to_stripe_price_id,
                $changeType,
            );
        } catch (Throwable $exception) {
            throw new BillingManualReviewRequired(
                'Der kanonische Stripe-Schedule konnte nach der Remote-Änderung nicht bestätigt werden.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  Closure(): array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function preparedRemoteOperation(
        BillingChangeIntent $intent,
        string $operation,
        string $targetType,
        string $targetId,
        string $idempotencyKey,
        Closure $payload,
    ): array {
        return DB::transaction(function () use (
            $intent,
            $operation,
            $targetType,
            $targetId,
            $idempotencyKey,
            $payload,
        ): array {
            /** @var BillingChangeIntent $locked */
            $locked = BillingChangeIntent::query()
                ->lockForUpdate()
                ->findOrFail($intent->getKey());
            $context = is_array($locked->context) ? $locked->context : [];
            $operations = is_array($context['remote_operations'] ?? null)
                ? $context['remote_operations']
                : [];
            $stored = $operations[$operation] ?? null;

            if (is_array($stored)) {
                $storedPayload = $stored['payload'] ?? null;
                if (
                    ($stored['target_type'] ?? null) !== $targetType
                    || ($stored['target_id'] ?? null) !== $targetId
                    || ($stored['idempotency_key'] ?? null) !== $idempotencyKey
                    || ! is_array($storedPayload)
                    || ($stored['payload_sha256'] ?? null)
                        !== $this->canonicalPayload->hash($storedPayload)
                ) {
                    throw new RuntimeException(
                        'Die persistierte Stripe-Remote-Operation ist widersprüchlich.',
                    );
                }

                return $this->canonicalPayload->normalize($storedPayload);
            }

            $normalized = $this->canonicalPayload->normalize($payload());
            $operations[$operation] = [
                'target_type' => $targetType,
                'target_id' => $targetId,
                'idempotency_key' => $idempotencyKey,
                'payload_sha256' => $this->canonicalPayload->hash($normalized),
                'payload' => $normalized,
            ];
            $context['remote_operations'] = $operations;
            $locked->forceFill(['context' => $context])->save();
            $intent->setAttribute('context', $context);

            return $normalized;
        }, 3);
    }

    private function remoteOperationTargetId(
        BillingChangeIntent $intent,
        string $operation,
        string $targetType,
    ): ?string {
        $stored = data_get(
            $intent->context,
            "remote_operations.{$operation}",
        );
        if (! is_array($stored)) {
            return null;
        }
        $targetId = $stored['target_id'] ?? null;
        if (
            ($stored['target_type'] ?? null) !== $targetType
            || ! is_string($targetId)
            || $targetId === ''
        ) {
            throw new RuntimeException(
                'Die persistierte Stripe-Schedule-Operation besitzt kein eindeutiges Ziel.',
            );
        }

        return $targetId;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function mergeContext(
        BillingChangeIntent $intent,
        array $values,
    ): void {
        DB::transaction(function () use ($intent, $values): void {
            /** @var BillingChangeIntent $locked */
            $locked = BillingChangeIntent::query()
                ->lockForUpdate()
                ->findOrFail($intent->getKey());
            $context = is_array($locked->context) ? $locked->context : [];
            $context = [...$context, ...$values];
            $locked->forceFill(['context' => $context])->save();
            $intent->setAttribute('context', $context);
        }, 3);
    }

    private function finalize(
        BillingChangeIntent $intent,
        CarbonInterface $effectiveAt,
        bool $targetIsCurrent,
    ): BillingChangeIntent {
        return DB::transaction(function () use (
            $intent,
            $effectiveAt,
            $targetIsCurrent,
        ): BillingChangeIntent {
            /** @var BillingChangeIntent $lockedIntent */
            $lockedIntent = BillingChangeIntent::query()
                ->lockForUpdate()
                ->findOrFail($intent->getKey());
            /** @var Company $company */
            $company = Company::query()
                ->lockForUpdate()
                ->findOrFail($lockedIntent->company_id);
            if (
                $company->stripe_subscription_id
                    !== $lockedIntent->stripe_subscription_id
                || ! in_array($company->current_plan_id, [
                    $lockedIntent->from_plan_id,
                    $lockedIntent->to_plan_id,
                ], true)
            ) {
                throw new RuntimeException(
                    'Das lokale Firmenabonnement hat sich während des Stripe-Abgleichs geändert.',
                );
            }

            $company->forceFill([
                'current_plan_id' => $targetIsCurrent
                    ? $lockedIntent->to_plan_id
                    : $company->current_plan_id,
                'pending_plan_id' => $targetIsCurrent
                    ? null
                    : $lockedIntent->to_plan_id,
                'pending_plan_effective_at' => $targetIsCurrent
                    ? null
                    : $effectiveAt,
            ])->save();
            $lockedIntent->forceFill([
                'status' => 'applied',
                'active_company_key' => null,
                'last_error' => null,
                'effective_at' => $effectiveAt,
                'applied_at' => now(),
            ])->save();

            return $lockedIntent;
        }, 3);
    }

    private function markForReconciliation(
        BillingChangeIntent $intent,
    ): void {
        BillingChangeIntent::query()
            ->whereKey($intent->getKey())
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->update([
                'status' => 'reconcile',
                'last_error' => self::RETRYABLE_ERROR,
                'updated_at' => now(),
            ]);
    }

    private function markForManualReview(
        BillingChangeIntent $intent,
    ): void {
        BillingChangeIntent::query()
            ->whereKey($intent->getKey())
            ->whereNotIn('status', ['applied', 'closed'])
            ->update([
                'status' => 'manual_review',
                'last_error' => self::MANUAL_REVIEW_ERROR,
                'updated_at' => now(),
            ]);
    }

    private function lockKey(Company $company): string
    {
        return 'stripe-billing-change-company:'.$company->getKey();
    }

    private function requiredPrice(Plan $plan): string
    {
        if (! is_string($plan->stripe_price_id) || $plan->stripe_price_id === '') {
            throw new DomainException(
                'Für den Tarifwechsel fehlt eine Stripe-Price-ID.',
            );
        }

        return $plan->stripe_price_id;
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

        $id = data_get($value, 'id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
