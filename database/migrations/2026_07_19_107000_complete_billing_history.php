<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_stripe_prices', function (Blueprint $table): void {
            $table->string('tax_behavior', 24)->default('unspecified')->after('term_months');
            $table->string('tax_code', 80)->nullable()->after('tax_behavior');
            $table->json('plan_snapshot')->nullable()->after('tax_code');
        });

        DB::table('plan_stripe_prices')
            ->join('plans', 'plans.id', '=', 'plan_stripe_prices.plan_id')
            ->select([
                'plan_stripe_prices.id',
                'plan_stripe_prices.stripe_product_id',
                'plan_stripe_prices.stripe_price_id',
                'plan_stripe_prices.price_cents',
                'plan_stripe_prices.currency',
                'plan_stripe_prices.term_months',
                'plans.slug',
                'plans.name',
                'plans.description',
                'plans.active_jobs_limit',
                'plans.seat_limit',
                'plans.ai_credits_monthly',
                'plans.job_boosts_per_term',
                'plans.visa_credits_per_term',
                'plans.features',
            ])
            ->orderBy('plan_stripe_prices.id')
            ->each(function (object $price): void {
                $features = is_string($price->features)
                    ? json_decode($price->features, true)
                    : null;
                $features = is_array($features) ? $features : null;
                $taxBehavior = data_get($features, 'billing.tax_behavior');
                $taxBehavior = in_array($taxBehavior, ['inclusive', 'exclusive', 'unspecified'], true)
                    ? $taxBehavior
                    : 'unspecified';
                $taxCode = data_get($features, 'billing.tax_code');
                $taxCode = is_string($taxCode) && $taxCode !== '' && mb_strlen($taxCode) <= 80
                    ? $taxCode
                    : null;
                $snapshot = [
                    'slug' => $price->slug,
                    'name' => $price->name,
                    'description' => $price->description,
                    'active_jobs_limit' => $price->active_jobs_limit === null ? null : (int) $price->active_jobs_limit,
                    'seat_limit' => $price->seat_limit === null ? null : (int) $price->seat_limit,
                    'ai_credits_monthly' => $price->ai_credits_monthly === null ? null : (int) $price->ai_credits_monthly,
                    'job_boosts_per_term' => $price->job_boosts_per_term === null ? null : (int) $price->job_boosts_per_term,
                    'visa_credits_per_term' => $price->visa_credits_per_term === null ? null : (int) $price->visa_credits_per_term,
                    'features' => $features,
                ];
                $versionHash = hash('sha256', implode('|', [
                    $price->stripe_product_id,
                    $price->stripe_price_id,
                    $price->price_cents,
                    strtoupper((string) $price->currency),
                    $price->term_months,
                    $taxBehavior,
                    $taxCode ?? '',
                    json_encode($snapshot, JSON_THROW_ON_ERROR),
                ]));

                DB::table('plan_stripe_prices')->where('id', $price->id)->update([
                    'tax_behavior' => $taxBehavior,
                    'tax_code' => $taxCode,
                    'plan_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'version_hash' => $versionHash,
                    'updated_at' => now(),
                ]);
            });

        Schema::create('company_billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_invoice_id')->unique();
            $table->string('number')->nullable()->index();
            $table->string('status', 32)->index();
            $table->char('currency', 3);
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('amount_paid_cents')->default(0);
            $table->bigInteger('amount_due_cents')->default(0);
            $table->string('billing_reason')->nullable();
            $table->text('hosted_invoice_url')->nullable();
            $table->text('invoice_pdf_url')->nullable();
            $table->json('promotion_codes')->nullable();
            $table->json('customer_tax_ids')->nullable();
            $table->json('billing_address')->nullable();
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('stripe_event_created_at')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'issued_at']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_billing_invoices');

        Schema::table('plan_stripe_prices', function (Blueprint $table): void {
            $table->dropColumn(['tax_behavior', 'tax_code', 'plan_snapshot']);
        });
    }
};
