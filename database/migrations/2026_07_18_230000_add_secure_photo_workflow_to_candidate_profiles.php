<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table): void {
            $table->string('profile_photo_quarantine_path')->nullable()->after('profile_photo_path');
            $table->string('profile_photo_disk')->default('private')->after('profile_photo_quarantine_path');
            $table->string('profile_photo_original_name')->nullable()->after('profile_photo_disk');
            $table->string('profile_photo_mime_type', 100)->nullable()->after('profile_photo_original_name');
            $table->unsignedBigInteger('profile_photo_size_bytes')->nullable()->after('profile_photo_mime_type');
            $table->string('profile_photo_scan_result', 40)->nullable()->after('profile_photo_size_bytes');
            $table->timestamp('profile_photo_scan_completed_at')->nullable()->after('profile_photo_scan_result');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'profile_photo_quarantine_path',
                'profile_photo_disk',
                'profile_photo_original_name',
                'profile_photo_mime_type',
                'profile_photo_size_bytes',
                'profile_photo_scan_result',
                'profile_photo_scan_completed_at',
            ]);
        });
    }
};
