<?php

use App\Services\Analytics\ActivityEventBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $throughId = DB::table('activity_entries')->max('id');

        Schema::table('activity_entries', function (Blueprint $table): void {
            $table->string('data_quality', 24)->default('observed')->after('schema_version')->index();
        });

        if ($throughId !== null) {
            app(ActivityEventBackfill::class)->run((int) $throughId);
        }
    }

    public function down(): void
    {
        Schema::table('activity_entries', function (Blueprint $table): void {
            $table->dropColumn('data_quality');
        });
    }
};
