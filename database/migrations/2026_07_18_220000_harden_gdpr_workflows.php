<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gdpr_requests', function (Blueprint $table): void {
            $table->foreignId('verified_by')
                ->nullable()
                ->after('handled_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('approved_by')
                ->nullable()
                ->after('verified_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->boolean('legal_hold')->default(false)->after('reason');
            $table->text('legal_hold_reason')->nullable()->after('legal_hold');
            $table->timestamp('processing_started_at')->nullable()->after('verified_at');
            $table->timestamp('failed_at')->nullable()->after('processing_started_at');
            $table->text('failure_reason')->nullable()->after('failed_at');
            $table->timestamp('export_expires_at')->nullable()->after('export_path');
            $table->timestamp('downloaded_at')->nullable()->after('export_expires_at');
            $table->json('result_summary')->nullable()->after('downloaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('gdpr_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'legal_hold',
                'legal_hold_reason',
                'processing_started_at',
                'failed_at',
                'failure_reason',
                'export_expires_at',
                'downloaded_at',
                'result_summary',
            ]);
        });
    }
};
