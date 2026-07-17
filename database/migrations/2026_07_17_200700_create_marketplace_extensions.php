<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->string('email')->index();
            $table->string('role');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'email']);
        });

        Schema::create('company_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->string('disk')->default('private');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('scan_result')->default('pending');
            $table->timestamp('scan_completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('company_trust_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('response_rate', 5, 2)->nullable();
            $table->decimal('interview_attendance_rate', 5, 2)->nullable();
            $table->decimal('contract_compliance_rate', 5, 2)->nullable();
            $table->unsignedInteger('cases_count')->default(0);
            $table->boolean('is_top_company')->default(false);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('candidate_internal_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->json('metrics');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'candidate_profile_id', 'reviewer_id'], 'candidate_internal_review_unique');
        });

        Schema::create('document_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'expires_at']);
        });

        Schema::create('timeline_events', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('subject');
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event')->index();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
        });

        Schema::create('job_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->json('content');
            $table->boolean('is_premium')->default(false);
            $table->timestamps();
        });

        Schema::create('job_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 8);
            $table->string('title');
            $table->string('position');
            $table->longText('description');
            $table->timestamps();
            $table->unique(['job_posting_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_translations');
        Schema::dropIfExists('job_templates');
        Schema::dropIfExists('timeline_events');
        Schema::dropIfExists('document_access_grants');
        Schema::dropIfExists('candidate_internal_reviews');
        Schema::dropIfExists('company_trust_metrics');
        Schema::dropIfExists('company_media');
        Schema::dropIfExists('company_invitations');
    }
};
