<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_entries', function (Blueprint $table): void {
            $table->uuid('event_uuid')->nullable()->unique()->after('id');
            $table->unsignedSmallInteger('schema_version')->default(1)->after('event');
            $table->string('idempotency_key', 64)->nullable()->unique()->after('schema_version');
            $table->index(['company_id', 'event', 'occurred_at'], 'activity_company_event_time');
        });
    }

    public function down(): void
    {
        Schema::table('activity_entries', function (Blueprint $table): void {
            $table->dropIndex('activity_company_event_time');
            $table->dropUnique(['event_uuid']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['event_uuid', 'schema_version', 'idempotency_key']);
        });
    }
};
