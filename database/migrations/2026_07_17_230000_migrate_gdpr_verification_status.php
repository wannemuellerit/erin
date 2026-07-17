<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('gdpr_requests')
            ->where('status', 'identity_verification')
            ->update(['status' => 'verified']);
    }

    public function down(): void
    {
        DB::table('gdpr_requests')
            ->where('status', 'verified')
            ->update(['status' => 'identity_verification']);
    }
};
