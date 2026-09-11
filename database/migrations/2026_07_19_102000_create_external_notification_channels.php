<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_phone_channels')) {
            Schema::create('notification_phone_channels', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('channel', 16);
                $table->text('phone_e164');
                $table->string('phone_hash', 64);
                $table->char('country_code', 2);
                $table->string('verification_code_hash', 64)->nullable();
                $table->timestamp('verification_expires_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('consented_at')->nullable();
                $table->string('consent_version', 40)->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->time('quiet_hours_start')->nullable();
                $table->time('quiet_hours_end')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'channel'], 'notification_phone_user_channel_unique');
                $table->index(['phone_hash', 'channel'], 'notification_phone_lookup_index');
            });
        }

        if (! Schema::hasTable('external_notification_deliveries')) {
            Schema::create('external_notification_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('notification_delivery_id')->nullable();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('channel', 16);
                $table->string('event', 100);
                $table->string('idempotency_key', 64);
                $table->string('provider', 40)->nullable();
                $table->string('provider_message_id', 160)->nullable();
                $table->string('status', 30)->default('queued');
                $table->unsignedInteger('attempts')->default(0);
                $table->unsignedInteger('cost_micros')->default(0);
                $table->char('currency', 3)->default('EUR');
                $table->string('failure_code', 80)->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'channel', 'event', 'idempotency_key'], 'external_notification_idempotency_unique');
                $table->index(['channel', 'status', 'created_at'], 'external_notification_metric_index');
                $table->unique(['provider', 'provider_message_id'], 'external_notification_provider_message_unique');
                $table->foreign('notification_delivery_id', 'external_notification_parent_fk')
                    ->references('id')->on('notification_deliveries')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('external_notification_webhook_receipts')) {
            Schema::create('external_notification_webhook_receipts', function (Blueprint $table): void {
                $table->id();
                $table->string('provider', 40);
                $table->string('provider_event_id', 160);
                $table->string('event_type', 80);
                $table->string('payload_hash', 64);
                $table->timestamp('processed_at');
                $table->timestamps();
                $table->unique(['provider', 'provider_event_id'], 'external_notification_webhook_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('external_notification_webhook_receipts');
        Schema::dropIfExists('external_notification_deliveries');
        Schema::dropIfExists('notification_phone_channels');
    }
};
