<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_histories', function (Blueprint $table): void {
            $table->string('event', 32)->default('login')->after('email')->index();
        });

        DB::table('login_histories')
            ->where('successful', false)
            ->update(['event' => 'failed']);
    }

    public function down(): void
    {
        Schema::table('login_histories', function (Blueprint $table): void {
            $table->dropIndex(['event']);
            $table->dropColumn('event');
        });
    }
};
