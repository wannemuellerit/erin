<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruiter_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('candidate_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignId('interview_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_posting_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 180);
            $table->text('note')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->timestamp('due_at')->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('notified_at')->nullable()->index();
            $table->timestamps();

            $table->index(['company_id', 'assignee_id', 'completed_at', 'due_at'], 'reminders_company_due_index');
        });

        Schema::create('candidate_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('original_filename');
            $table->string('disk')->default('private');
            $table->string('storage_path');
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedSmallInteger('total_rows')->default(0);
            $table->unsignedSmallInteger('imported_rows')->default(0);
            $table->unsignedSmallInteger('failed_rows')->default(0);
            $table->json('mapping')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });

        Schema::create('candidate_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('row_number');
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('email')->nullable();
            $table->string('current_position', 160)->nullable();
            $table->string('desired_position', 160)->nullable();
            $table->char('current_country_code', 2)->nullable();
            $table->decimal('experience_years', 4, 1)->nullable();
            $table->string('language_level', 20)->nullable();
            $table->string('status', 30)->default('imported')->index();
            $table->json('payload')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->unique(['candidate_import_id', 'row_number']);
            $table->index(['company_id', 'email']);
        });

        Schema::create('activity_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('event', 120)->index();
            $table->nullableMorphs('subject');
            $table->string('visibility', 30)->default('company');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();

            $table->index(['company_id', 'occurred_at']);
            $table->index(['subject_user_id', 'occurred_at']);
        });

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->string('external_system', 30)->nullable()->after('status');
            $table->string('external_id', 120)->nullable()->after('external_system');
            $table->string('sync_status', 30)->default('pending')->after('external_id');
            $table->text('sync_error')->nullable()->after('sync_status');
            $table->timestamp('last_synced_at')->nullable()->after('sync_error');
            $table->unique(['external_system', 'external_id']);
        });

        Schema::table('support_ticket_messages', function (Blueprint $table): void {
            $table->string('external_article_id', 120)->nullable()->unique()->after('author_id');
            $table->string('source', 30)->default('erin')->after('external_article_id');
            $table->string('delivery_status', 30)->default('pending')->after('source');
            $table->timestamp('delivered_at')->nullable()->after('delivery_status');
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table): void {
            $table->dropUnique(['external_article_id']);
            $table->dropColumn(['external_article_id', 'source', 'delivery_status', 'delivered_at']);
        });

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropUnique(['external_system', 'external_id']);
            $table->dropColumn([
                'external_system',
                'external_id',
                'sync_status',
                'sync_error',
                'last_synced_at',
            ]);
        });

        Schema::dropIfExists('activity_entries');
        Schema::dropIfExists('candidate_import_rows');
        Schema::dropIfExists('candidate_imports');
        Schema::dropIfExists('recruiter_reminders');
    }
};
