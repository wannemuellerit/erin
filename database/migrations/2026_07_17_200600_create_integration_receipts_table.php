<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('pending_plan_id')
                ->nullable()
                ->after('current_plan_id')
                ->constrained('plans')
                ->nullOnDelete();
            $table->timestamp('pending_plan_effective_at')->nullable()->after('pending_plan_id');
        });

        Schema::create('integration_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event_id');
            $table->string('event_type')->index();
            $table->string('status')->default('processing')->index();
            $table->char('payload_hash', 64);
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_receipts');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_plan_id');
            $table->dropColumn('pending_plan_effective_at');
        });
    }
};
