<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->string('request_key', 64)->nullable()->unique()->after('id');
            $table->string('review_decision', 20)->nullable()->after('output');
            $table->foreignId('reviewed_by')->nullable()->after('review_decision')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropUnique(['request_key']);
            $table->dropColumn(['request_key', 'review_decision', 'reviewed_at']);
        });
    }
};
