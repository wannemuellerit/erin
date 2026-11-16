<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->text('external_account_id');
            $table->string('external_account_hash', 64);
            $table->char('country_code', 2);
            $table->char('currency_code', 3);
            $table->string('status', 24)->default('pending');
            $table->string('kyc_status', 24)->default('pending');
            $table->string('terms_version', 32);
            $table->timestamp('terms_accepted_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index('external_account_hash');
        });

        Schema::create('referral_payout_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('referral_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('payout_account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency_code', 3);
            $table->string('status', 32)->default('awaiting_account');
            $table->unsignedTinyInteger('fraud_score')->default(0);
            $table->json('fraud_signals')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('payout_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id');
            $table->string('payload_hash', 64);
            $table->boolean('signature_valid')->default(false);
            $table->string('event_type');
            $table->foreignId('referral_payout_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_webhook_receipts');
        Schema::dropIfExists('referral_payout_intents');
        Schema::dropIfExists('payout_accounts');
    }
};
