<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruiter_reminders', function (Blueprint $table): void {
            $table->string('timezone', 64)->default('UTC')->after('priority');
            $table->string('recurrence', 16)->nullable()->after('timezone');
            $table->timestamp('recurrence_ends_at')->nullable()->after('recurrence');
            $table->uuid('series_uuid')->nullable()->after('recurrence_ends_at');
            $table->string('occurrence_key', 100)->nullable()->unique()->after('series_uuid');
            $table->timestamp('snoozed_until')->nullable()->after('due_at');
            $table->timestamp('discarded_at')->nullable()->index()->after('completed_at');
            $table->softDeletes();
        });

        Schema::create('recruiter_reminder_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recruiter_reminder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 32);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();
            $table->index(['recruiter_reminder_id', 'created_at'], 'reminder_events_timeline_index');
        });

        Schema::table('candidate_imports', function (Blueprint $table): void {
            $table->timestamp('cancellation_requested_at')->nullable()->index()->after('started_at');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_requested_at');
        });

        Schema::create('candidate_bulk_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('action', 32);
            $table->string('selection_mode', 16)->default('ids');
            $table->json('filter_snapshot')->nullable();
            $table->json('payload');
            $table->string('request_hash', 64);
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'created_by', 'idempotency_key'], 'candidate_bulk_idempotency_unique');
        });

        Schema::create('candidate_bulk_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_bulk_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('candidate_updated_at')->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->string('reason')->nullable();
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamps();
            $table->unique(['candidate_bulk_batch_id', 'candidate_profile_id'], 'candidate_bulk_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_bulk_batch_items');
        Schema::dropIfExists('candidate_bulk_batches');
        Schema::table('candidate_imports', function (Blueprint $table): void {
            $table->dropColumn(['cancellation_requested_at', 'cancelled_at']);
        });
        Schema::dropIfExists('recruiter_reminder_events');
        Schema::table('recruiter_reminders', function (Blueprint $table): void {
            $table->dropUnique(['occurrence_key']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'timezone', 'recurrence', 'recurrence_ends_at', 'series_uuid',
                'occurrence_key', 'snoozed_until', 'discarded_at',
            ]);
        });
    }
};
