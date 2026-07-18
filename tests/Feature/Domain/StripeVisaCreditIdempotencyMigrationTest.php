<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Execute a migration scenario against an isolated table in the normal test DB.
 *
 * @param  Closure(object, string): void  $callback
 */
function erinInStripeMigrationSandbox(Closure $callback): void
{
    $tableName = 'entitlement_ledgers_idempotency_sandbox';

    try {
        Schema::dropIfExists($tableName);
        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('resource');
            $table->integer('amount');
            $table->string('source');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $migration = require database_path(
            'migrations/2026_07_18_150000_enforce_stripe_visa_purchase_idempotency.php',
        );

        if (! is_object($migration)) {
            throw new RuntimeException('Die Stripe-Migration konnte nicht geladen werden.');
        }

        $callback($migration, $tableName);
    } finally {
        Schema::dropIfExists($tableName);
    }
}

function erinInvokeStripeMigration(
    object $migration,
    string $method,
    string $tableName,
): void {
    if (! method_exists($migration, $method)) {
        throw new RuntimeException("Die Migrationsmethode {$method} fehlt.");
    }

    (new ReflectionMethod($migration, $method))->invoke(
        $migration,
        $tableName,
    );
}

function erinStripeMigrationObjectName(
    string $baseName,
    string $tableName,
): string {
    return substr($baseName, 0, 55).'_'.substr(
        hash('sha256', $tableName),
        0,
        8,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function erinHistoricalStripeGrant(array $overrides = []): array
{
    return [
        'company_id' => 100,
        'resource' => 'visa',
        'amount' => 5,
        'source' => 'stripe_purchase',
        'reference_type' => 'stripe_payment',
        'reference_id' => null,
        'expires_at' => null,
        'metadata' => json_encode(
            ['stripe_reference' => 'pi_historical_valid'],
            JSON_THROW_ON_ERROR,
        ),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

it('upgrades and rolls back without rewriting historical payment identity', function () {
    erinInStripeMigrationSandbox(function (
        object $migration,
        string $tableName,
    ): void {
        $ledgerId = DB::table($tableName)->insertGetId(
            erinHistoricalStripeGrant(),
        );
        $before = (array) DB::table($tableName)->find($ledgerId);

        erinInvokeStripeMigration($migration, 'addToTable', $tableName);
        erinInvokeStripeMigration($migration, 'addToTable', $tableName);

        $generatedColumn = DB::selectOne(<<<'SQL'
            SELECT COLLATION_NAME, EXTRA
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = 'stripe_payment_intent_id'
            SQL, [$tableName]);

        expect(Schema::hasColumn(
            $tableName,
            'stripe_payment_intent_id',
        ))->toBeTrue()
            ->and(DB::table($tableName)
                ->where('id', $ledgerId)
                ->value('stripe_payment_intent_id'))
            ->toBe('pi_historical_valid')
            ->and($generatedColumn?->COLLATION_NAME)->toBe('ascii_bin')
            ->and($generatedColumn?->EXTRA)->toContain('STORED GENERATED');

        erinInvokeStripeMigration($migration, 'removeFromTable', $tableName);
        erinInvokeStripeMigration($migration, 'removeFromTable', $tableName);
        $after = (array) DB::table($tableName)->find($ledgerId);

        expect(Schema::hasColumn(
            $tableName,
            'stripe_payment_intent_id',
        ))->toBeFalse()
            ->and($after)->toBe($before);

        // Re-running the exact migration after rollback must remain lossless.
        erinInvokeStripeMigration($migration, 'addToTable', $tableName);
        erinInvokeStripeMigration($migration, 'addToTable', $tableName);
        expect(DB::table($tableName)
            ->where('id', $ledgerId)
            ->value('stripe_payment_intent_id'))
            ->toBe('pi_historical_valid');
        erinInvokeStripeMigration($migration, 'removeFromTable', $tableName);
        erinInvokeStripeMigration($migration, 'removeFromTable', $tableName);
    });
});

it('rejects a partially installed idempotency structure without adding more fragments', function () {
    erinInStripeMigrationSandbox(function (
        object $migration,
        string $tableName,
    ): void {
        DB::statement(
            "ALTER TABLE `{$tableName}` ADD COLUMN `stripe_payment_intent_id` VARCHAR(255) NULL",
        );

        expect(fn () => erinInvokeStripeMigration(
            $migration,
            'addToTable',
            $tableName,
        ))->toThrow(RuntimeException::class, 'teilweise installiert')
            ->and(Schema::hasColumn(
                $tableName,
                'stripe_payment_intent_id',
            ))->toBeTrue();

        $indexes = Schema::getIndexes($tableName);
        expect(collect($indexes)->contains(
            fn (array $index): bool => $index['columns']
                === ['stripe_payment_intent_id'],
        ))->toBeFalse();
    });
});

it('rejects a fully named but deviating idempotency structure', function () {
    erinInStripeMigrationSandbox(function (
        object $migration,
        string $tableName,
    ): void {
        $indexName = erinStripeMigrationObjectName(
            'entitlement_ledgers_stripe_payment_intent_unique',
            $tableName,
        );
        $checkName = erinStripeMigrationObjectName(
            'entitlement_ledgers_stripe_purchase_check',
            $tableName,
        );
        DB::statement(
            "ALTER TABLE `{$tableName}`"
            .' ADD COLUMN `stripe_payment_intent_id` VARCHAR(255) NULL,'
            ." ADD UNIQUE INDEX `{$indexName}` (`stripe_payment_intent_id`),"
            ." ADD CONSTRAINT `{$checkName}` CHECK (`amount` <> 0)",
        );

        expect(fn () => erinInvokeStripeMigration(
            $migration,
            'addToTable',
            $tableName,
        ))->toThrow(RuntimeException::class, 'weicht')
            ->and(DB::table($tableName)->count())->toBe(0);
    });
});

it('keeps legacy writers compatible while the global unique identity blocks duplicates', function () {
    erinInStripeMigrationSandbox(function (
        object $migration,
        string $tableName,
    ): void {
        erinInvokeStripeMigration($migration, 'addToTable', $tableName);

        $stripeId = DB::table($tableName)->insertGetId(
            erinHistoricalStripeGrant([
                'company_id' => 101,
                'metadata' => '{"stripe_reference":"pi_legacy_writer"}',
            ]),
        );
        $ordinaryId = DB::table($tableName)->insertGetId(
            erinHistoricalStripeGrant([
                'company_id' => 102,
                'source' => 'subscription_grant',
                'reference_type' => null,
                'metadata' => null,
            ]),
        );

        expect(DB::table($tableName)
            ->where('id', $stripeId)
            ->value('stripe_payment_intent_id'))
            ->toBe('pi_legacy_writer')
            ->and(DB::table($tableName)
                ->where('id', $ordinaryId)
                ->value('stripe_payment_intent_id'))
            ->toBeNull()
            ->and(fn () => DB::table($tableName)->insert(
                erinHistoricalStripeGrant([
                    'company_id' => 999,
                    'metadata' => '{"stripe_reference":"pi_legacy_writer"}',
                ]),
            ))->toThrow(QueryException::class);

        erinInvokeStripeMigration($migration, 'removeFromTable', $tableName);
    });
});

it('fails an invalid historical payment before leaving any schema fragment', function (
    array $changes,
) {
    erinInStripeMigrationSandbox(function (
        object $migration,
        string $tableName,
    ) use ($changes): void {
        DB::table($tableName)->insert(
            erinHistoricalStripeGrant($changes),
        );

        expect(fn () => erinInvokeStripeMigration(
            $migration,
            'addToTable',
            $tableName,
        ))
            ->toThrow(QueryException::class)
            ->and(Schema::hasColumn(
                $tableName,
                'stripe_payment_intent_id',
            ))->toBeFalse()
            ->and(DB::table($tableName)->count())->toBe(1);
    });
})->with([
    'fehlende Metadaten' => [['metadata' => null]],
    'fehlende PaymentIntent-ID' => [[
        'metadata' => '{"other":"value"}',
    ]],
    'ungültige PaymentIntent-ID' => [[
        'metadata' => '{"stripe_reference":"cs_historical"}',
    ]],
    'numerische PaymentIntent-ID' => [[
        'metadata' => '{"stripe_reference":123}',
    ]],
    'historischer Ablauf' => [['expires_at' => '2030-01-01 00:00:00']],
]);

it('fails conflicting historical tenants atomically and preserves both rows', function () {
    erinInStripeMigrationSandbox(function (
        object $migration,
        string $tableName,
    ): void {
        DB::table($tableName)->insert([
            erinHistoricalStripeGrant([
                'company_id' => 100,
                'amount' => 5,
                'metadata' => '{"stripe_reference":"pi_cross_tenant_history"}',
            ]),
            erinHistoricalStripeGrant([
                'company_id' => 200,
                'amount' => 9,
                'metadata' => '{"stripe_reference":"pi_cross_tenant_history"}',
            ]),
        ]);

        $before = DB::table($tableName)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        expect(fn () => erinInvokeStripeMigration(
            $migration,
            'addToTable',
            $tableName,
        ))
            ->toThrow(QueryException::class)
            ->and(Schema::hasColumn(
                $tableName,
                'stripe_payment_intent_id',
            ))->toBeFalse()
            ->and(DB::table($tableName)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all())->toBe($before);
    });
});

it('can restart cleanly after an atomic validation failure', function () {
    erinInStripeMigrationSandbox(function (
        object $migration,
        string $tableName,
    ): void {
        $ledgerId = DB::table($tableName)->insertGetId(
            erinHistoricalStripeGrant(['metadata' => null]),
        );

        expect(fn () => erinInvokeStripeMigration(
            $migration,
            'addToTable',
            $tableName,
        ))
            ->toThrow(QueryException::class)
            ->and(Schema::hasColumn(
                $tableName,
                'stripe_payment_intent_id',
            ))->toBeFalse();

        DB::table($tableName)
            ->where('id', $ledgerId)
            ->update([
                'metadata' => '{"stripe_reference":"pi_reconciled_history"}',
            ]);

        erinInvokeStripeMigration($migration, 'addToTable', $tableName);

        expect(DB::table($tableName)
            ->where('id', $ledgerId)
            ->value('stripe_payment_intent_id'))
            ->toBe('pi_reconciled_history');
        erinInvokeStripeMigration($migration, 'removeFromTable', $tableName);
    });
});

it('keeps case-distinct historical Stripe identities separate', function () {
    erinInStripeMigrationSandbox(function (
        object $migration,
        string $tableName,
    ): void {
        DB::table($tableName)->insert([
            erinHistoricalStripeGrant([
                'metadata' => '{"stripe_reference":"pi_HistoricalCase"}',
            ]),
            erinHistoricalStripeGrant([
                'metadata' => '{"stripe_reference":"pi_historicalcase"}',
            ]),
        ]);

        erinInvokeStripeMigration($migration, 'addToTable', $tableName);

        expect(DB::table($tableName)
            ->orderBy('stripe_payment_intent_id')
            ->pluck('stripe_payment_intent_id')
            ->all())->toBe([
                'pi_HistoricalCase',
                'pi_historicalcase',
            ]);
        erinInvokeStripeMigration($migration, 'removeFromTable', $tableName);
    });
});
