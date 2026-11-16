<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 100);
            $table->string('idempotency_key', 191);
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->timestamp('queued_at');
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_channel', 100)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event', 'idempotency_key'], 'notification_delivery_unique');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
