<?php

use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function erinBillingControlMigration(): object
{
    $migration = require database_path(
        'migrations/2026_07_18_170000_create_stripe_billing_change_control_tables.php',
    );
    if (! is_object($migration)) {
        throw new RuntimeException(
            'Die Stripe-Billing-Control-Migration konnte nicht geladen werden.',
        );
    }

    return $migration;
}

it('resumes after a MySQL DDL crash without replacing completed tables or backfills', function () {
    $migration = erinBillingControlMigration();
    $markerPrice = 'price_restart_marker';

    try {
        $migration->up();
        $migration->up();
        DB::table('stripe_addon_prices')->updateOrInsert(
            ['stripe_price_id' => $markerPrice],
            [
                'code' => 'restart_marker',
                'stripe_product_id' => 'prod_restart_marker',
                'is_enabled' => true,
                'activated_at' => now(),
                'retired_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        Schema::drop('billing_change_intents');
        expect(Schema::hasTable('plan_stripe_prices'))->toBeTrue()
            ->and(Schema::hasTable('stripe_addon_prices'))->toBeTrue()
            ->and(Schema::hasTable('billing_change_intents'))->toBeFalse();

        $migration->up();
        $migration->up();

        expect(Schema::hasTable('billing_change_intents'))->toBeTrue()
            ->and(DB::table('stripe_addon_prices')
                ->where('stripe_price_id', $markerPrice)
                ->count())->toBe(1);
    } finally {
        if (Schema::hasTable('stripe_addon_prices')) {
            DB::table('stripe_addon_prices')
                ->where('stripe_price_id', $markerPrice)
                ->delete();
        }
        $migration->up();
    }
});

it('fails closed on a drifted completed table instead of guessing a repair', function () {
    $migration = erinBillingControlMigration();
    $migration->up();

    try {
        Schema::table(
            'stripe_addon_prices',
            fn ($table) => $table->string('unexpected_drift')->nullable(),
        );

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'Spaltendefinition')
            ->and(Schema::hasTable('billing_change_intents'))->toBeTrue()
            ->and(Schema::hasColumn(
                'stripe_addon_prices',
                'unexpected_drift',
            ))->toBeTrue();
    } finally {
        if (Schema::hasColumn(
            'stripe_addon_prices',
            'unexpected_drift',
        )) {
            Schema::table(
                'stripe_addon_prices',
                fn ($table) => $table->dropColumn('unexpected_drift'),
            );
        }
        $migration->up();
    }
});

it('treats a completely removed control schema as a repeatable down state', function () {
    $migration = erinBillingControlMigration();

    try {
        $migration->up();
        $migration->down();
        $migration->down();

        expect(Schema::hasTable('billing_change_intents'))->toBeFalse()
            ->and(Schema::hasTable('stripe_addon_prices'))->toBeFalse()
            ->and(Schema::hasTable('plan_stripe_prices'))->toBeFalse();
    } finally {
        $migration->up();
    }

    expect(Schema::hasTable('billing_change_intents'))->toBeTrue()
        ->and(Schema::hasTable('stripe_addon_prices'))->toBeTrue()
        ->and(Schema::hasTable('plan_stripe_prices'))->toBeTrue();
});

it('fails closed when an existing Stripe Price is registered in both billing roles', function () {
    $migration = erinBillingControlMigration();
    $migration->up();
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_migration_role_collision',
        'stripe_price_id' => 'price_migration_role_collision',
    ]);
    $migration->up();

    try {
        DB::table('stripe_addon_prices')->insert([
            'code' => 'migration_role_collision',
            'stripe_product_id' => 'prod_migration_role_addon',
            'stripe_price_id' => 'price_migration_role_collision',
            'is_enabled' => true,
            'activated_at' => now(),
            'retired_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'zugleich')
            ->and(DB::table('stripe_addon_prices')
                ->where(
                    'stripe_price_id',
                    'price_migration_role_collision',
                )
                ->count())->toBe(1)
            ->and(DB::table('plan_stripe_prices')
                ->where(
                    'stripe_price_id',
                    'price_migration_role_collision',
                )
                ->count())->toBe(1);
    } finally {
        DB::table('stripe_addon_prices')
            ->where(
                'stripe_price_id',
                'price_migration_role_collision',
            )
            ->delete();
        $plan->delete();
        $migration->up();
    }
});

it('rejects a configured add-on Price that already belongs to a plan before any DDL', function () {
    $migration = erinBillingControlMigration();
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_migration_config_collision',
        'stripe_price_id' => 'price_migration_config_collision',
    ]);
    $previous = config('services.stripe.seat_price_id');

    try {
        config()->set(
            'services.stripe.seat_price_id',
            'price_migration_config_collision',
        );

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'zugleich')
            ->and(DB::table('stripe_addon_prices')
                ->where(
                    'stripe_price_id',
                    'price_migration_config_collision',
                )
                ->doesntExist())->toBeTrue();
    } finally {
        config()->set('services.stripe.seat_price_id', $previous);
        $plan->delete();
        $migration->up();
    }
});

it('rejects a visa add-on that collides with a retired historical base price', function () {
    $migration = erinBillingControlMigration();
    $plan = Plan::factory()->create([
        'stripe_product_id' => 'prod_migration_historical_base',
        'stripe_price_id' => 'price_migration_historical_base_v1',
    ]);
    $migration->up();
    $plan->forceFill([
        'stripe_price_id' => 'price_migration_historical_base_v2',
    ])->save();
    $migration->up();
    $previous = config('services.stripe.visa_price_id');

    try {
        config()->set(
            'services.stripe.visa_price_id',
            'price_migration_historical_base_v1',
        );

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'historische')
            ->and(DB::table('stripe_addon_prices')
                ->where(
                    'stripe_price_id',
                    'price_migration_historical_base_v1',
                )
                ->doesntExist())->toBeTrue();
    } finally {
        config()->set('services.stripe.visa_price_id', $previous);
        $plan->delete();
        $migration->up();
    }
});

it('rejects one configured price reused for seat and visa roles', function () {
    $migration = erinBillingControlMigration();
    $previousSeat = config('services.stripe.seat_price_id');
    $previousVisa = config('services.stripe.visa_price_id');

    try {
        config()->set(
            'services.stripe.seat_price_id',
            'price_migration_duplicate_addon',
        );
        config()->set(
            'services.stripe.visa_price_id',
            'price_migration_duplicate_addon',
        );

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'mehreren Add-on-Rollen')
            ->and(DB::table('stripe_addon_prices')
                ->where(
                    'stripe_price_id',
                    'price_migration_duplicate_addon',
                )
                ->doesntExist())->toBeTrue();
    } finally {
        config()->set(
            'services.stripe.seat_price_id',
            $previousSeat,
        );
        config()->set(
            'services.stripe.visa_price_id',
            $previousVisa,
        );
        $migration->up();
    }
});
