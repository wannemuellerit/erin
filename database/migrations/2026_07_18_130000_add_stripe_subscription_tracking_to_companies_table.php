<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasSubscriptionId = Schema::hasColumn('companies', 'stripe_subscription_id');
        $hasGeneration = Schema::hasColumn('companies', 'stripe_subscription_generation');
        $hasNextGeneration = Schema::hasColumn(
            'companies',
            'stripe_next_subscription_generation',
        );

        Schema::table('companies', function (Blueprint $table) use (
            $hasSubscriptionId,
            $hasGeneration,
            $hasNextGeneration,
        ): void {
            if (! $hasSubscriptionId) {
                $table->string('stripe_subscription_id')
                    ->nullable()
                    ->after('stripe_id')
                    ->index();
            }

            if (! $hasGeneration) {
                $table->unsignedBigInteger('stripe_subscription_generation')
                    ->default(0)
                    ->after('stripe_subscription_id');
            }

            if (! $hasNextGeneration) {
                $table->unsignedBigInteger('stripe_next_subscription_generation')
                    ->default(0)
                    ->after('stripe_subscription_generation');
            }
        });

        DB::table('companies')
            ->select(['id', 'subscription_status'])
            ->orderBy('id')
            ->chunkById(100, function ($companies): void {
                foreach ($companies as $company) {
                    $subscriptionId = DB::table('subscriptions')
                        ->where('company_id', $company->id)
                        ->where('type', 'default')
                        ->when(
                            is_string($company->subscription_status),
                            fn ($query) => $query->orderByRaw(
                                'CASE WHEN stripe_status = ? THEN 0 ELSE 1 END',
                                [$company->subscription_status],
                            ),
                        )
                        ->latest('created_at')
                        ->value('stripe_id');

                    if (is_string($subscriptionId)) {
                        DB::table('companies')
                            ->where('id', $company->id)
                            ->update(['stripe_subscription_id' => $subscriptionId]);
                    }
                }
            });

        $legacyOrderingColumns = array_values(array_filter([
            'last_stripe_subscription_event_created',
            'last_stripe_subscription_event_id',
            'last_stripe_subscription_event_type',
        ], fn (string $column): bool => Schema::hasColumn('companies', $column)));

        if ($legacyOrderingColumns !== []) {
            Schema::table('companies', function (Blueprint $table) use (
                $legacyOrderingColumns,
            ): void {
                $table->dropColumn($legacyOrderingColumns);
            });
        }
    }

    public function down(): void
    {
        $trackingColumns = array_values(array_filter([
            'stripe_subscription_id',
            'stripe_subscription_generation',
            'stripe_next_subscription_generation',
        ], fn (string $column): bool => Schema::hasColumn('companies', $column)));

        if ($trackingColumns !== []) {
            Schema::table('companies', function (Blueprint $table) use (
                $trackingColumns,
            ): void {
                $table->dropColumn($trackingColumns);
            });
        }
    }
};
