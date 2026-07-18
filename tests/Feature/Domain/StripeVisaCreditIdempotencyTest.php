<?php

use App\Listeners\SyncStripePurchase;
use App\Models\Company;
use App\Models\EntitlementLedger;
use App\Models\IntegrationReceipt;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\StripePurchaseSignature;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Events\WebhookReceived;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function erinPaymentIntentReplayPayload(
    Company $company,
    string $eventId,
    string $paymentIntentId,
    int $credits,
): array {
    $priceId = 'price_idempotency_visa';

    return [
        'id' => $eventId,
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'data' => ['object' => [
            'id' => 'cs_'.$eventId,
            'mode' => 'payment',
            'payment_status' => 'paid',
            'payment_intent' => $paymentIntentId,
            'metadata' => [
                'purchase_type' => 'visa_credits',
                'company_id' => (string) $company->getKey(),
                'credits' => (string) $credits,
                'price_id' => $priceId,
                'erin_signature_version' => StripePurchaseSignature::VERSION,
                'erin_purchase_signature' => app(
                    StripePurchaseSignature::class,
                )->sign((int) $company->getKey(), $credits, $priceId),
            ],
        ]],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function erinRawStripeVisaGrant(Company $company, array $overrides = []): array
{
    return [
        'company_id' => $company->getKey(),
        'resource' => 'visa',
        'amount' => 3,
        'source' => 'stripe_purchase',
        'reference_type' => 'stripe_payment',
        'reference_id' => null,
        'expires_at' => null,
        'metadata' => ['stripe_reference' => 'pi_raw_constraint'],
        ...$overrides,
    ];
}

it('returns the one database-backed grant for repeated delivery of a payment intent', function () {
    $company = Company::factory()->create();
    $entitlements = app(EntitlementService::class);

    $first = $entitlements->grantPurchasedVisaCredits(
        $company,
        5,
        'pi_repeated_delivery',
    );
    $second = $entitlements->grantPurchasedVisaCredits(
        $company,
        5,
        'pi_repeated_delivery',
    );

    expect($second->is($first))->toBeTrue()
        ->and($first->stripe_payment_intent_id)->toBe('pi_repeated_delivery')
        ->and(EntitlementLedger::query()->count())->toBe(1)
        ->and(EntitlementLedger::query()->sum('amount'))->toEqual(5);
});

it('keeps payment intent identity binary and case-sensitive', function () {
    $company = Company::factory()->create();
    $entitlements = app(EntitlementService::class);

    $upper = $entitlements->grantPurchasedVisaCredits(
        $company,
        1,
        'pi_CaseSensitive',
    );
    $lower = $entitlements->grantPurchasedVisaCredits(
        $company,
        1,
        'pi_casesensitive',
    );

    expect($upper->is($lower))->toBeFalse()
        ->and($upper->stripe_payment_intent_id)->toBe('pi_CaseSensitive')
        ->and($lower->stripe_payment_intent_id)->toBe('pi_casesensitive')
        ->and(EntitlementLedger::query()->count())->toBe(2)
        ->and(EntitlementLedger::query()->sum('amount'))->toEqual(2);
});

it('credits one payment intent once across distinct valid Stripe event IDs', function () {
    config()->set('cashier.secret', 'sk_test_distinct_event_replay');
    $company = Company::factory()->create();
    $listener = app(SyncStripePurchase::class);

    $listener->handle(new WebhookReceived(erinPaymentIntentReplayPayload(
        $company,
        'evt_distinct_delivery_one',
        'pi_distinct_event_replay',
        5,
    )));
    $listener->handle(new WebhookReceived(erinPaymentIntentReplayPayload(
        $company,
        'evt_distinct_delivery_two',
        'pi_distinct_event_replay',
        5,
    )));

    expect(IntegrationReceipt::query()
        ->where('provider', 'stripe:received')
        ->where('status', 'processed')
        ->count())->toBe(2)
        ->and(EntitlementLedger::query()
            ->where('stripe_payment_intent_id', 'pi_distinct_event_replay')
            ->count())->toBe(1)
        ->and(EntitlementLedger::query()
            ->where('stripe_payment_intent_id', 'pi_distinct_event_replay')
            ->sum('amount'))->toEqual(5);
});

it('marks a distinct conflicting Stripe event failed without changing granted credits', function () {
    config()->set('cashier.secret', 'sk_test_conflicting_event_replay');
    $company = Company::factory()->create();
    $listener = app(SyncStripePurchase::class);

    $listener->handle(new WebhookReceived(erinPaymentIntentReplayPayload(
        $company,
        'evt_conflicting_delivery_original',
        'pi_conflicting_event_replay',
        5,
    )));

    expect(fn () => $listener->handle(new WebhookReceived(
        erinPaymentIntentReplayPayload(
            $company,
            'evt_conflicting_delivery_changed',
            'pi_conflicting_event_replay',
            6,
        ),
    )))->toThrow(
        DomainException::class,
        'bereits für einen abweichenden Visakauf verwendet',
    );

    expect(IntegrationReceipt::query()
        ->where('provider', 'stripe:received')
        ->where('event_id', 'evt_conflicting_delivery_original')
        ->value('status'))->toBe('processed')
        ->and(IntegrationReceipt::query()
            ->where('provider', 'stripe:received')
            ->where('event_id', 'evt_conflicting_delivery_changed')
            ->value('status'))->toBe('failed')
        ->and(EntitlementLedger::query()
            ->where('stripe_payment_intent_id', 'pi_conflicting_event_replay')
            ->count())->toBe(1)
        ->and(EntitlementLedger::query()
            ->where('stripe_payment_intent_id', 'pi_conflicting_event_replay')
            ->sum('amount'))->toEqual(5);
});

it('fails closed when one payment intent is replayed with different purchase facts', function () {
    $creditedCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $entitlements = app(EntitlementService::class);

    $entitlements->grantPurchasedVisaCredits(
        $creditedCompany,
        5,
        'pi_conflicting_replay',
    );

    expect(fn () => $entitlements->grantPurchasedVisaCredits(
        $creditedCompany,
        6,
        'pi_conflicting_replay',
    ))->toThrow(
        DomainException::class,
        'bereits für einen abweichenden Visakauf verwendet',
    )->and(fn () => $entitlements->grantPurchasedVisaCredits(
        $otherCompany,
        5,
        'pi_conflicting_replay',
    ))->toThrow(
        DomainException::class,
        'bereits für einen abweichenden Visakauf verwendet',
    );

    expect(EntitlementLedger::query()->count())->toBe(1)
        ->and(EntitlementLedger::query()
            ->where('company_id', $creditedCompany->getKey())
            ->sum('amount'))->toEqual(5)
        ->and(EntitlementLedger::query()
            ->where('company_id', $otherCompany->getKey())
            ->sum('amount'))->toEqual(0);
});

it('rejects invalid payment intent references before touching the ledger', function (
    string $reference,
) {
    $company = Company::factory()->create();

    expect(fn () => app(EntitlementService::class)
        ->grantPurchasedVisaCredits($company, 1, $reference))
        ->toThrow(DomainException::class, 'Zahlungsreferenz ist ungültig')
        ->and(EntitlementLedger::query()->count())->toBe(0);
})->with([
    'leer' => '',
    'Checkout-Session statt PaymentIntent' => 'cs_wrong_object',
    'Leerzeichen' => 'pi_not allowed',
    'Pfadzeichen' => 'pi_not/allowed',
    'zu lang' => 'pi_'.str_repeat('a', 253),
]);

it('enforces idempotency and valid Stripe grants when application code is bypassed', function () {
    $company = Company::factory()->create();
    $entitlements = app(EntitlementService::class);

    $entitlements->grantPurchasedVisaCredits(
        $company,
        3,
        'pi_database_constraint',
    );

    expect(fn () => EntitlementLedger::query()->create([
        'company_id' => $company->getKey(),
        'resource' => 'visa',
        'amount' => 3,
        'source' => 'stripe_purchase',
        'reference_type' => 'stripe_payment',
        'metadata' => ['stripe_reference' => 'pi_database_constraint'],
    ]))->toThrow(QueryException::class)
        ->and(fn () => EntitlementLedger::query()->create([
            'company_id' => $company->getKey(),
            'resource' => 'visa',
            'amount' => 0,
            'source' => 'stripe_purchase',
            'reference_type' => 'stripe_payment',
            'metadata' => ['stripe_reference' => 'pi_zero_credit'],
        ]))->toThrow(QueryException::class);

    expect(EntitlementLedger::query()->count())->toBe(1)
        ->and(EntitlementLedger::query()->sum('amount'))->toEqual(3);
});

it('rejects hostile Stripe ledger shapes at the MySQL boundary', function (
    array $changes,
) {
    $company = Company::factory()->create();

    expect(fn () => EntitlementLedger::query()->create(
        erinRawStripeVisaGrant($company, $changes),
    ))->toThrow(QueryException::class)
        ->and(EntitlementLedger::query()->count())->toBe(0);
})->with([
    'falsche Ressource' => [['resource' => 'ai']],
    'negative Menge' => [['amount' => -1]],
    'falscher Referenztyp' => [['reference_type' => 'stripe_checkout']],
    'fehlender Referenztyp' => [['reference_type' => null]],
    'numerische Referenz-ID' => [['reference_id' => 42]],
    'ablaufende Gutschrift' => [['expires_at' => '2030-01-01 00:00:00']],
    'fehlende Metadaten' => [['metadata' => null]],
    'fehlender Metadaten-Schlüssel' => [['metadata' => ['other' => 'value']]],
    'numerischer Metadatenwert' => [['metadata' => ['stripe_reference' => 123]]],
    'Checkout-ID statt PaymentIntent' => [['metadata' => ['stripe_reference' => 'cs_not_a_pi']]],
    'nicht-ASCII PaymentIntent' => [['metadata' => ['stripe_reference' => 'pi_ümlaut']]],
]);

it('derives the immutable identity column and never lets another source occupy it', function () {
    $company = Company::factory()->create();
    $manual = EntitlementLedger::query()->create([
        'company_id' => $company->getKey(),
        'resource' => 'visa',
        'amount' => 1,
        'source' => 'manual_adjustment',
        'reference_type' => 'admin',
        'metadata' => ['stripe_reference' => 'pi_not_owned_by_manual_source'],
    ]);

    expect($manual->refresh()->stripe_payment_intent_id)->toBeNull()
        ->and(fn () => DB::table('entitlement_ledgers')->insert([
            ...erinRawStripeVisaGrant($company, [
                'metadata' => json_encode(
                    ['stripe_reference' => 'pi_generated_write'],
                    JSON_THROW_ON_ERROR,
                ),
            ]),
            'stripe_payment_intent_id' => 'pi_generated_write',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class)
        ->and(EntitlementLedger::query()->count())->toBe(1);
});

it('allows only one tenant to win a concurrent payment intent conflict', function () {
    $companyIds = [
        (int) Company::factory()->create()->getKey(),
        (int) Company::factory()->create()->getKey(),
    ];
    $directory = sys_get_temp_dir().'/erin-visa-idempotency-'.bin2hex(random_bytes(8));
    $startFile = $directory.'/start';
    $children = [];

    if (! mkdir($directory, 0700) && ! is_dir($directory)) {
        throw new RuntimeException('Das temporäre Parallelitätsverzeichnis konnte nicht erstellt werden.');
    }

    // Child connections must see the company fixture, so intentionally end the
    // RefreshDatabase transaction. Teardown detects this and refreshes the
    // database before the next test.
    DB::connection()->commit();
    DB::purge();

    for ($worker = 0; $worker < 2; $worker++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Der Parallelitätsprozess konnte nicht gestartet werden.');
        }

        if ($pid === 0) {
            $resultFile = $directory.'/result-'.$worker.'.json';
            $readyFile = $directory.'/ready-'.$worker;
            $deadline = microtime(true) + 5;
            $exitCode = 0;

            try {
                DB::purge();
                file_put_contents($readyFile, 'ready', LOCK_EX);

                while (! is_file($startFile) && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($startFile)) {
                    throw new RuntimeException('Die Parallelitätsbarriere wurde nicht geöffnet.');
                }

                $workerCompany = Company::query()->findOrFail(
                    $companyIds[$worker],
                );
                $ledger = app(EntitlementService::class)
                    ->grantPurchasedVisaCredits(
                        $workerCompany,
                        7,
                        'pi_parallel_workers',
                    );

                file_put_contents($resultFile, json_encode([
                    'ledger_id' => $ledger->getKey(),
                    'amount' => $ledger->amount,
                    'company_id' => $ledger->company_id,
                ], JSON_THROW_ON_ERROR), LOCK_EX);
            } catch (Throwable $exception) {
                $exitCode = 1;
                file_put_contents($resultFile, json_encode([
                    'error' => $exception::class,
                    'message' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR), LOCK_EX);
            }

            exit($exitCode);
        }

        $children[] = $pid;
    }

    $readyDeadline = microtime(true) + 5;
    while (
        (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1'))
        && microtime(true) < $readyDeadline
    ) {
        usleep(10_000);
    }

    file_put_contents($startFile, 'go', LOCK_EX);

    $exitCodes = [];
    foreach ($children as $pid) {
        $status = 0;
        pcntl_waitpid($pid, $status);
        $exitCodes[] = pcntl_wifexited($status)
            ? pcntl_wexitstatus($status)
            : 255;
    }

    DB::purge();
    $results = [
        json_decode(
            (string) file_get_contents($directory.'/result-0.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ),
        json_decode(
            (string) file_get_contents($directory.'/result-1.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ),
    ];
    $ledgerCount = EntitlementLedger::query()
        ->where('stripe_payment_intent_id', 'pi_parallel_workers')
        ->count();
    $creditedAmount = EntitlementLedger::query()
        ->where('stripe_payment_intent_id', 'pi_parallel_workers')
        ->sum('amount');
    $creditedCompanyId = EntitlementLedger::query()
        ->where('stripe_payment_intent_id', 'pi_parallel_workers')
        ->value('company_id');

    foreach (glob($directory.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);

    sort($exitCodes);
    $successfulResults = array_values(array_filter(
        $results,
        fn (array $result): bool => isset($result['ledger_id']),
    ));
    $failedResults = array_values(array_filter(
        $results,
        fn (array $result): bool => isset($result['error']),
    ));
    /** @var array{ledger_id: int, amount: int, company_id: int}|null $successfulResult */
    $successfulResult = $successfulResults[0] ?? null;
    /** @var array{error: class-string, message: string}|null $failedResult */
    $failedResult = $failedResults[0] ?? null;

    if ($successfulResult === null || $failedResult === null) {
        throw new RuntimeException('Der Cross-Tenant-Parallelitätstest lieferte kein eindeutiges Ergebnis.');
    }

    expect($exitCodes)->toBe([0, 1])
        ->and($successfulResults)->toHaveCount(1)
        ->and($failedResults)->toHaveCount(1)
        ->and($successfulResult['amount'])->toBe(7)
        ->and($failedResult['error'])->toBe(DomainException::class)
        ->and($ledgerCount)->toBe(1)
        ->and($creditedAmount)->toEqual(7)
        ->and(in_array((int) $creditedCompanyId, $companyIds, true))->toBeTrue();
})->skip(
    ! function_exists('pcntl_fork'),
    'Die pcntl-Erweiterung ist für den Parallelitätstest erforderlich.',
);
