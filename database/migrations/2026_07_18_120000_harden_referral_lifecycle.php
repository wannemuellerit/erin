<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table): void {
            $table->timestamp('approval_notified_at')->nullable()->after('hold_until');
        });

        Schema::create('referral_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['referral_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_status_histories');

        Schema::table('referrals', function (Blueprint $table): void {
            $table->dropColumn('approval_notified_at');
        });
    }
};
