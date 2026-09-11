<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->string('email_hash', 64)->unique();
            $table->string('reason');
            $table->string('provider');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_delivery_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('provider_event_id');
            $table->foreignId('notification_delivery_id')->nullable()
                ->constrained('notification_deliveries')->nullOnDelete();
            $table->string('recipient_hash', 64);
            $table->string('event_type');
            $table->string('payload_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['provider', 'provider_event_id'], 'mail_event_provider_id_uq');
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_delivery_events');
        Schema::dropIfExists('email_suppressions');
    }
};
