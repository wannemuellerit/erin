<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('support_tickets', 'external_updated_at_ms')) {
            $this->assertWatermarkColumn();

            return;
        }

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->unsignedBigInteger('external_updated_at_ms')
                ->nullable()
                ->after('last_synced_at');
        });

        $this->assertWatermarkColumn();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('support_tickets', 'external_updated_at_ms')) {
            return;
        }

        $this->assertWatermarkColumn();

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropColumn('external_updated_at_ms');
        });
    }

    private function assertWatermarkColumn(): void
    {
        $column = collect(Schema::getColumns('support_tickets'))
            ->firstWhere('name', 'external_updated_at_ms');

        if (
            ! is_array($column)
            || strtolower($column['type']) !== 'bigint unsigned'
            || $column['nullable'] !== true
            || $column['default'] !== null
            || $column['auto_increment'] !== false
            || $column['generation'] !== null
        ) {
            throw new RuntimeException(
                'Der Zammad-Event-Watermark besitzt nicht das erwartete Schema.',
            );
        }
    }
};
