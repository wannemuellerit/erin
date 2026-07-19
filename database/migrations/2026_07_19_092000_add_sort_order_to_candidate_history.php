<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_experiences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('candidate_profile_id');
        });
        Schema::table('candidate_educations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('candidate_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_experiences', fn (Blueprint $table) => $table->dropColumn('sort_order'));
        Schema::table('candidate_educations', fn (Blueprint $table) => $table->dropColumn('sort_order'));
    }
};
