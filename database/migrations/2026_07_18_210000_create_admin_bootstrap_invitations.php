<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_bootstrap_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->index();
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique();
            $table->boolean('allow_role_change')->default(false);
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_change_required_at')
                ->nullable()
                ->after('onboarding_completed_at')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_change_required_at');
        });

        Schema::dropIfExists('admin_bootstrap_invitations');
    }
};
