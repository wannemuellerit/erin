<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('company_locations')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('occupation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('position');
            $table->longText('description');
            $table->decimal('expected_experience_years', 4, 1)->nullable();
            $table->text('language_notes')->nullable();
            $table->unsignedTinyInteger('hours_min')->nullable();
            $table->unsignedTinyInteger('hours_max')->nullable();
            $table->string('employment_type')->default('full_time')->index();
            $table->unsignedInteger('compensation_min_cents')->nullable();
            $table->unsignedInteger('compensation_max_cents')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->string('compensation_interval')->default('year');
            $table->string('status')->default('draft')->index();
            $table->boolean('is_remote')->default(false);
            $table->boolean('visa_package_available')->default(false);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('boosted_until')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'slug']);
            $table->index(['occupation_id', 'status']);
        });

        Schema::create('job_screening_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->string('question');
            $table->string('type')->default('text');
            $table->boolean('is_required')->default(false);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('job_skill', function (Blueprint $table) {
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('importance')->default(1);
            $table->decimal('minimum_experience_years', 4, 1)->nullable();
            $table->primary(['job_posting_id', 'skill_id']);
        });

        Schema::create('job_language', function (Blueprint $table) {
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('minimum_level', 8);
            $table->boolean('is_required')->default(true);
            $table->primary(['job_posting_id', 'language_id']);
        });

        Schema::create('job_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('disk')->default('private');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('new')->index();
            $table->longText('cover_letter')->nullable();
            $table->decimal('match_score', 5, 2)->nullable()->index();
            $table->json('match_breakdown')->nullable();
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('identity_revealed_at')->nullable();
            $table->timestamp('documents_shared_at')->nullable();
            $table->timestamps();
            $table->unique(['job_posting_id', 'candidate_profile_id']);
        });

        Schema::create('application_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['application_id', 'created_at']);
        });

        Schema::create('application_screening_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_screening_question_id')->constrained()->cascadeOnDelete();
            $table->text('answer')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'job_screening_question_id'], 'application_question_unique');
        });

        Schema::create('job_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->text('message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['job_posting_id', 'candidate_profile_id']);
        });

        Schema::create('talent_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('talent_list_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['talent_list_id', 'candidate_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_list_members');
        Schema::dropIfExists('talent_lists');
        Schema::dropIfExists('job_invitations');
        Schema::dropIfExists('application_screening_answers');
        Schema::dropIfExists('application_status_histories');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('job_media');
        Schema::dropIfExists('job_language');
        Schema::dropIfExists('job_skill');
        Schema::dropIfExists('job_screening_questions');
        Schema::dropIfExists('job_postings');
    }
};
