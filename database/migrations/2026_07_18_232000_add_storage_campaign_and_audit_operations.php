<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('storage_quota_bytes')->nullable()->after('last_active_at');
        });

        Schema::create('upload_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bytes');
            $table->unsignedBigInteger('reclaim_bytes')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('ad_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 160);
            $table->string('placement', 50)->default('dashboard');
            $table->string('audience', 30)->default('all');
            $table->json('content');
            $table->string('target_url', 500)->nullable();
            $table->string('media_disk', 40)->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime', 120)->nullable();
            $table->unsignedBigInteger('media_size_bytes')->nullable();
            $table->boolean('enabled')->default(false)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ad_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->date('event_date');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamps();
            $table->unique(['ad_campaign_id', 'user_id', 'type', 'event_date'], 'ad_event_daily_unique');
        });

        Schema::create('security_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 191)->unique();
            $table->string('type', 80)->index();
            $table->string('severity', 20)->default('warning')->index();
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->json('details')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->timestamp('retention_locked_at')->nullable()->index();
            $table->string('retention_lock_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropColumn(['retention_locked_at', 'retention_lock_reason']);
        });
        Schema::dropIfExists('security_alerts');
        Schema::dropIfExists('ad_events');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('upload_reservations');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('storage_quota_bytes');
        });
    }
};
