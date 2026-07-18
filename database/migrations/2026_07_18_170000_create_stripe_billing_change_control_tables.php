<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertUniqueConfiguredPlanPrices();
        $this->ensurePlanStripePricesTable();
        $this->ensureStripeAddonPricesTable();
        $this->ensureBillingChangeIntentsTable();
        $this->backfillPlanPrices();
        $this->backfillConfiguredAddOnPrices();
    }

    public function down(): void
    {
        $this->dropVerifiedTable('billing_change_intents');
        $this->dropVerifiedTable('stripe_addon_prices');
        $this->dropVerifiedTable('plan_stripe_prices');
    }

    private function ensurePlanStripePricesTable(): void
    {
        if (! Schema::hasTable('plan_stripe_prices')) {
            Schema::create(
                'plan_stripe_prices',
                function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('plan_id')
                        ->constrained()
                        ->cascadeOnDelete();
                    $table->string('stripe_product_id');
                    $table->string('stripe_price_id')->unique();
                    $table->unsignedInteger('price_cents');
                    $table->char('currency', 3);
                    $table->unsignedSmallInteger('term_months');
                    $table->char('version_hash', 64);
                    $table->string('source', 40);
                    $table->boolean('is_current')->default(true)->index();
                    $table->timestamp('activated_at');
                    $table->timestamp('retired_at')->nullable();
                    $table->timestamps();

                    $table->unique(['plan_id', 'version_hash']);
                    $table->index(['plan_id', 'is_current']);
                    $table->index([
                        'stripe_product_id',
                        'stripe_price_id',
                    ]);
                },
            );
        }

        $this->assertTableShape('plan_stripe_prices');
    }

    private function ensureStripeAddonPricesTable(): void
    {
        if (! Schema::hasTable('stripe_addon_prices')) {
            Schema::create(
                'stripe_addon_prices',
                function (Blueprint $table): void {
                    $table->id();
                    $table->string('code', 80);
                    $table->string('stripe_product_id')->nullable();
                    $table->string('stripe_price_id')->unique();
                    $table->boolean('is_enabled')
                        ->default(true)
                        ->index();
                    $table->timestamp('activated_at');
                    $table->timestamp('retired_at')->nullable();
                    $table->timestamps();

                    $table->index(['code', 'is_enabled']);
                },
            );
        }

        $this->assertTableShape('stripe_addon_prices');
    }

    private function ensureBillingChangeIntentsTable(): void
    {
        if (! Schema::hasTable('billing_change_intents')) {
            Schema::create(
                'billing_change_intents',
                function (Blueprint $table): void {
                    $table->id();
                    $table->uuid('public_id')->unique();
                    $table->foreignId('company_id')
                        ->constrained()
                        ->cascadeOnDelete();
                    $table->foreignId('from_plan_id')
                        ->constrained('plans')
                        ->restrictOnDelete();
                    $table->foreignId('to_plan_id')
                        ->constrained('plans')
                        ->restrictOnDelete();
                    $table->foreignId('requested_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();
                    $table->string('change_type', 24);
                    $table->string('status', 24)
                        ->default('pending')
                        ->index();
                    $table->string('active_company_key')
                        ->nullable()
                        ->unique();
                    $table->string('stripe_subscription_id');
                    $table->string('from_stripe_price_id');
                    $table->string('to_stripe_price_id');
                    $table->string('stripe_idempotency_key')->unique();
                    $table->json('context')->nullable();
                    $table->unsignedSmallInteger('attempts')->default(0);
                    $table->text('last_error')->nullable();
                    $table->timestamp('effective_at')->nullable();
                    $table->timestamp('applied_at')->nullable();
                    $table->timestamps();

                    $table->index(['company_id', 'status']);
                    $table->index([
                        'stripe_subscription_id',
                        'status',
                    ]);
                },
            );
        }

        $this->assertTableShape('billing_change_intents');
    }

    private function dropVerifiedTable(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $this->assertTableShape($tableName);
        Schema::drop($tableName);
    }

    private function assertUniqueConfiguredPlanPrices(): void
    {
        $duplicatePriceIds = DB::table('plans')
            ->whereNotNull('stripe_price_id')
            ->groupBy('stripe_price_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('stripe_price_id');
        if ($duplicatePriceIds->isNotEmpty()) {
            throw new RuntimeException(
                'Eine bestehende Stripe-Price-ID ist mehreren Erin-Paketen zugeordnet.',
            );
        }

        $configuredAddOnPrices = collect([
            config('services.stripe.seat_price_id'),
            config('services.stripe.visa_price_id'),
        ])->filter(
            static fn (mixed $priceId): bool => is_string($priceId)
                && $priceId !== '',
        )->values();
        if (
            $configuredAddOnPrices->isNotEmpty()
            && DB::table('plans')
                ->whereIn('stripe_price_id', $configuredAddOnPrices)
                ->exists()
        ) {
            throw new RuntimeException(
                'Eine konfigurierte Stripe-Price ist zugleich einem Erin-Paket und einem Add-on zugeordnet.',
            );
        }
        if (
            $configuredAddOnPrices->count()
                !== $configuredAddOnPrices->unique()->count()
        ) {
            throw new RuntimeException(
                'Eine konfigurierte Stripe-Price ist mehreren Add-on-Rollen zugeordnet.',
            );
        }
        if (
            $configuredAddOnPrices->isNotEmpty()
            && Schema::hasTable('plan_stripe_prices')
            && DB::table('plan_stripe_prices')
                ->whereIn('stripe_price_id', $configuredAddOnPrices)
                ->exists()
        ) {
            throw new RuntimeException(
                'Eine konfigurierte Add-on-Price ist bereits als historische Erin-Basis-Price registriert.',
            );
        }

        if (
            Schema::hasTable('stripe_addon_prices')
            && DB::table('stripe_addon_prices')
                ->join(
                    'plans',
                    'plans.stripe_price_id',
                    '=',
                    'stripe_addon_prices.stripe_price_id',
                )
                ->exists()
        ) {
            throw new RuntimeException(
                'Eine bestehende Stripe-Price ist zugleich als Add-on und Basispaket registriert.',
            );
        }

        if (
            Schema::hasTable('stripe_addon_prices')
            && Schema::hasTable('plan_stripe_prices')
            && DB::table('stripe_addon_prices')
                ->join(
                    'plan_stripe_prices',
                    'plan_stripe_prices.stripe_price_id',
                    '=',
                    'stripe_addon_prices.stripe_price_id',
                )
                ->exists()
        ) {
            throw new RuntimeException(
                'Eine historische Stripe-Price ist zugleich als Add-on und Basispaket registriert.',
            );
        }
    }

    private function backfillPlanPrices(): void
    {
        DB::transaction(function (): void {
            $now = now();
            DB::table('plans')
                ->whereNotNull('stripe_product_id')
                ->whereNotNull('stripe_price_id')
                ->whereNotNull('price_cents')
                ->whereNotNull('term_months')
                ->orderBy('id')
                ->each(function (object $plan) use ($now): void {
                    $currency = strtoupper((string) $plan->currency);
                    $versionHash = hash('sha256', implode('|', [
                        $plan->stripe_product_id,
                        $plan->stripe_price_id,
                        $plan->price_cents,
                        $currency,
                        $plan->term_months,
                    ]));
                    $existing = DB::table('plan_stripe_prices')
                        ->where(
                            'stripe_price_id',
                            $plan->stripe_price_id,
                        )
                        ->lockForUpdate()
                        ->first();
                    if (
                        $existing !== null
                        && (
                            (int) $existing->plan_id !== (int) $plan->id
                            || $existing->stripe_product_id
                                !== $plan->stripe_product_id
                            || (int) $existing->price_cents
                                !== (int) $plan->price_cents
                            || $existing->currency !== $currency
                            || (int) $existing->term_months
                                !== (int) $plan->term_months
                            || $existing->version_hash !== $versionHash
                        )
                    ) {
                        throw new RuntimeException(
                            'Eine bestehende Stripe-Preisversion widerspricht dem Erin-Paket.',
                        );
                    }

                    DB::table('plan_stripe_prices')
                        ->where('plan_id', $plan->id)
                        ->where(
                            'stripe_price_id',
                            '!=',
                            $plan->stripe_price_id,
                        )
                        ->where('is_current', true)
                        ->update([
                            'is_current' => false,
                            'retired_at' => $now,
                            'updated_at' => $now,
                        ]);

                    if ($existing !== null) {
                        DB::table('plan_stripe_prices')
                            ->where('id', $existing->id)
                            ->update([
                                'is_current' => true,
                                'retired_at' => null,
                                'updated_at' => $now,
                            ]);

                        return;
                    }

                    DB::table('plan_stripe_prices')->insert([
                        'plan_id' => $plan->id,
                        'stripe_product_id' => $plan->stripe_product_id,
                        'stripe_price_id' => $plan->stripe_price_id,
                        'price_cents' => $plan->price_cents,
                        'currency' => $currency,
                        'term_months' => $plan->term_months,
                        'version_hash' => $versionHash,
                        'source' => 'migration',
                        'is_current' => true,
                        'activated_at' => $now,
                        'retired_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });
        }, 3);
    }

    private function backfillConfiguredAddOnPrices(): void
    {
        foreach ([
            [
                'code' => 'recruiter_seat',
                'price' => config('services.stripe.seat_price_id'),
                'product' => config(
                    'services.stripe.seat_product_id',
                ),
            ],
            [
                'code' => 'visa_package',
                'price' => config('services.stripe.visa_price_id'),
                'product' => config(
                    'services.stripe.visa_product_id',
                ),
            ],
        ] as $configuration) {
            $this->backfillConfiguredAddOnPrice(
                $configuration['code'],
                $configuration['price'],
                $configuration['product'],
            );
        }
    }

    private function backfillConfiguredAddOnPrice(
        mixed $code,
        mixed $priceId,
        mixed $configuredProduct,
    ): void {
        if (! is_string($code) || $code === '') {
            throw new RuntimeException(
                'Die Stripe-Add-on-Rolle ist ungültig.',
            );
        }
        if (! is_string($priceId) || $priceId === '') {
            return;
        }
        $productId = is_string($configuredProduct)
            && $configuredProduct !== ''
                ? $configuredProduct
                : null;

        DB::transaction(function () use (
            $code,
            $priceId,
            $productId,
        ): void {
            if (
                DB::table('plans')
                    ->where('stripe_price_id', $priceId)
                    ->exists()
                || DB::table('plan_stripe_prices')
                    ->where('stripe_price_id', $priceId)
                    ->exists()
            ) {
                throw new RuntimeException(
                    'Die konfigurierte Add-on-Price ist bereits als Erin-Basispaket registriert.',
                );
            }

            $now = now();
            $existing = DB::table('stripe_addon_prices')
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
                throw new RuntimeException(
                    'Eine bestehende Stripe-Add-on-Price widerspricht der Konfiguration.',
                );
            }

            DB::table('stripe_addon_prices')
                ->where('code', $code)
                ->where('stripe_price_id', '!=', $priceId)
                ->whereNull('retired_at')
                ->update([
                    'retired_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($existing !== null) {
                DB::table('stripe_addon_prices')
                    ->where('id', $existing->id)
                    ->update([
                        'stripe_product_id' => $existing
                            ->stripe_product_id ?? $productId,
                        'is_enabled' => true,
                        'retired_at' => null,
                        'updated_at' => $now,
                    ]);

                return;
            }

            DB::table('stripe_addon_prices')->insert([
                'code' => $code,
                'stripe_product_id' => $productId,
                'stripe_price_id' => $priceId,
                'is_enabled' => true,
                'activated_at' => $now,
                'retired_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 3);
    }

    private function assertTableShape(string $tableName): void
    {
        $expectedColumns = $this->expectedColumns($tableName);
        $actualColumns = [];
        foreach (Schema::getColumns($tableName) as $column) {
            $actualColumns[$column['name']] = [
                strtolower($column['type']),
                (bool) $column['nullable'],
                $column['default'] === null
                    ? null
                    : (string) $column['default'],
                (bool) $column['auto_increment'],
                $column['generation'],
            ];
        }
        if ($actualColumns !== $expectedColumns) {
            throw new RuntimeException(
                "Die Tabelle {$tableName} weicht von der erwarteten Spaltendefinition ab.",
            );
        }

        $actualIndexes = array_map(
            fn (array $index): string => $this->schemaSignature([
                $index['columns'],
                (bool) $index['unique'],
                (bool) $index['primary'],
                strtolower($index['type']),
            ]),
            Schema::getIndexes($tableName),
        );
        $expectedIndexes = array_map(
            fn (array $index): string => $this->schemaSignature($index),
            $this->expectedIndexes($tableName),
        );
        sort($actualIndexes);
        sort($expectedIndexes);
        if ($actualIndexes !== $expectedIndexes) {
            throw new RuntimeException(
                "Die Tabelle {$tableName} weicht von der erwarteten Indexdefinition ab.",
            );
        }

        $actualForeignKeys = array_map(
            fn (array $foreignKey): string => $this->schemaSignature([
                $foreignKey['columns'],
                $foreignKey['foreign_table'],
                $foreignKey['foreign_columns'],
                strtolower((string) $foreignKey['on_update']),
                strtolower((string) $foreignKey['on_delete']),
            ]),
            Schema::getForeignKeys($tableName),
        );
        $expectedForeignKeys = array_map(
            fn (array $foreignKey): string => $this->schemaSignature(
                $foreignKey,
            ),
            $this->expectedForeignKeys($tableName),
        );
        sort($actualForeignKeys);
        sort($expectedForeignKeys);
        if ($actualForeignKeys !== $expectedForeignKeys) {
            throw new RuntimeException(
                "Die Tabelle {$tableName} weicht von der erwarteten Fremdschlüsseldefinition ab.",
            );
        }
    }

    /**
     * @return array<string, array{string, bool, string|null, bool, null}>
     */
    private function expectedColumns(string $tableName): array
    {
        return match ($tableName) {
            'plan_stripe_prices' => [
                'id' => ['bigint unsigned', false, null, true, null],
                'plan_id' => ['bigint unsigned', false, null, false, null],
                'stripe_product_id' => ['varchar(255)', false, null, false, null],
                'stripe_price_id' => ['varchar(255)', false, null, false, null],
                'price_cents' => ['int unsigned', false, null, false, null],
                'currency' => ['char(3)', false, null, false, null],
                'term_months' => ['smallint unsigned', false, null, false, null],
                'version_hash' => ['char(64)', false, null, false, null],
                'source' => ['varchar(40)', false, null, false, null],
                'is_current' => ['tinyint(1)', false, '1', false, null],
                'activated_at' => ['timestamp', false, null, false, null],
                'retired_at' => ['timestamp', true, null, false, null],
                'created_at' => ['timestamp', true, null, false, null],
                'updated_at' => ['timestamp', true, null, false, null],
            ],
            'stripe_addon_prices' => [
                'id' => ['bigint unsigned', false, null, true, null],
                'code' => ['varchar(80)', false, null, false, null],
                'stripe_product_id' => ['varchar(255)', true, null, false, null],
                'stripe_price_id' => ['varchar(255)', false, null, false, null],
                'is_enabled' => ['tinyint(1)', false, '1', false, null],
                'activated_at' => ['timestamp', false, null, false, null],
                'retired_at' => ['timestamp', true, null, false, null],
                'created_at' => ['timestamp', true, null, false, null],
                'updated_at' => ['timestamp', true, null, false, null],
            ],
            'billing_change_intents' => [
                'id' => ['bigint unsigned', false, null, true, null],
                'public_id' => ['char(36)', false, null, false, null],
                'company_id' => ['bigint unsigned', false, null, false, null],
                'from_plan_id' => ['bigint unsigned', false, null, false, null],
                'to_plan_id' => ['bigint unsigned', false, null, false, null],
                'requested_by' => ['bigint unsigned', true, null, false, null],
                'change_type' => ['varchar(24)', false, null, false, null],
                'status' => ['varchar(24)', false, 'pending', false, null],
                'active_company_key' => ['varchar(255)', true, null, false, null],
                'stripe_subscription_id' => ['varchar(255)', false, null, false, null],
                'from_stripe_price_id' => ['varchar(255)', false, null, false, null],
                'to_stripe_price_id' => ['varchar(255)', false, null, false, null],
                'stripe_idempotency_key' => ['varchar(255)', false, null, false, null],
                'context' => ['json', true, null, false, null],
                'attempts' => ['smallint unsigned', false, '0', false, null],
                'last_error' => ['text', true, null, false, null],
                'effective_at' => ['timestamp', true, null, false, null],
                'applied_at' => ['timestamp', true, null, false, null],
                'created_at' => ['timestamp', true, null, false, null],
                'updated_at' => ['timestamp', true, null, false, null],
            ],
            default => throw new RuntimeException(
                "Für {$tableName} fehlt die erwartete Spaltendefinition.",
            ),
        };
    }

    /**
     * @return list<array{list<string>, bool, bool, string}>
     */
    private function expectedIndexes(string $tableName): array
    {
        return match ($tableName) {
            'plan_stripe_prices' => [
                [['id'], true, true, 'btree'],
                [['is_current'], false, false, 'btree'],
                [['plan_id', 'is_current'], false, false, 'btree'],
                [['plan_id', 'version_hash'], true, false, 'btree'],
                [['stripe_price_id'], true, false, 'btree'],
                [['stripe_product_id', 'stripe_price_id'], false, false, 'btree'],
            ],
            'stripe_addon_prices' => [
                [['id'], true, true, 'btree'],
                [['code', 'is_enabled'], false, false, 'btree'],
                [['is_enabled'], false, false, 'btree'],
                [['stripe_price_id'], true, false, 'btree'],
            ],
            'billing_change_intents' => [
                [['id'], true, true, 'btree'],
                [['active_company_key'], true, false, 'btree'],
                [['company_id', 'status'], false, false, 'btree'],
                [['from_plan_id'], false, false, 'btree'],
                [['public_id'], true, false, 'btree'],
                [['requested_by'], false, false, 'btree'],
                [['status'], false, false, 'btree'],
                [['stripe_idempotency_key'], true, false, 'btree'],
                [['stripe_subscription_id', 'status'], false, false, 'btree'],
                [['to_plan_id'], false, false, 'btree'],
            ],
            default => throw new RuntimeException(
                "Für {$tableName} fehlt die erwartete Indexdefinition.",
            ),
        };
    }

    /**
     * @return list<array{list<string>, string, list<string>, string, string}>
     */
    private function expectedForeignKeys(string $tableName): array
    {
        return match ($tableName) {
            'plan_stripe_prices' => [
                [['plan_id'], 'plans', ['id'], 'no action', 'cascade'],
            ],
            'stripe_addon_prices' => [],
            'billing_change_intents' => [
                [['company_id'], 'companies', ['id'], 'no action', 'cascade'],
                [['from_plan_id'], 'plans', ['id'], 'no action', 'restrict'],
                [['requested_by'], 'users', ['id'], 'no action', 'set null'],
                [['to_plan_id'], 'plans', ['id'], 'no action', 'restrict'],
            ],
            default => throw new RuntimeException(
                "Für {$tableName} fehlt die erwartete Fremdschlüsseldefinition.",
            ),
        };
    }

    /**
     * @param  array<mixed>  $definition
     */
    private function schemaSignature(array $definition): string
    {
        return json_encode($definition, JSON_THROW_ON_ERROR);
    }
};
