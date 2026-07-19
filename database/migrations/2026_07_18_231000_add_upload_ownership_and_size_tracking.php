<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_media', function (Blueprint $table): void {
            $table->foreignId('uploaded_by')
                ->nullable()
                ->after('job_posting_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('candidate_imports', function (Blueprint $table): void {
            $table->unsignedBigInteger('size_bytes')
                ->default(0)
                ->after('storage_path');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_imports', function (Blueprint $table): void {
            $table->dropColumn('size_bytes');
        });

        Schema::table('job_media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('uploaded_by');
        });
    }
};
