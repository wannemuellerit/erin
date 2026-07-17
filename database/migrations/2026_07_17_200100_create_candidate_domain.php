<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occupations', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_de');
            $table->string('name_en');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_de');
            $table->string('name_en');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('occupation_skill', function (Blueprint $table) {
            $table->foreignId('occupation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->primary(['occupation_id', 'skill_id']);
        });

        Schema::create('candidate_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('occupation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable();
            $table->char('nationality_country_code', 2)->nullable();
            $table->char('current_country_code', 2)->nullable()->index();
            $table->string('current_city')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->text('summary')->nullable();
            $table->string('current_position')->nullable();
            $table->string('desired_position')->nullable()->index();
            $table->decimal('experience_years', 4, 1)->default(0);
            $table->string('highest_qualification')->nullable();
            $table->json('driving_licenses')->nullable();
            $table->boolean('travel_ready')->default(false);
            $table->boolean('relocation_ready')->default(false)->index();
            $table->date('available_from')->nullable()->index();
            $table->unsignedInteger('salary_expectation_cents')->nullable();
            $table->char('salary_currency', 3)->default('EUR');
            $table->json('employment_preferences')->nullable();
            $table->unsignedTinyInteger('weekly_hours')->nullable();
            $table->boolean('requires_visa')->default(true)->index();
            $table->boolean('has_work_permit')->default(false)->index();
            $table->string('profile_photo_path')->nullable();
            $table->unsignedTinyInteger('completeness')->default(0)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('candidate_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_profile_id')->constrained()->cascadeOnDelete();
            $table->string('employer');
            $table->string('position');
            $table->char('country_code', 2)->nullable();
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('candidate_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_profile_id')->constrained()->cascadeOnDelete();
            $table->string('institution');
            $table->string('qualification');
            $table->string('field')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->date('started_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('candidate_skill', function (Blueprint $table) {
            $table->foreignId('candidate_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('proficiency')->nullable();
            $table->decimal('experience_years', 4, 1)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->primary(['candidate_profile_id', 'skill_id']);
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('name_de');
            $table->string('name_en');
            $table->timestamps();
        });

        Schema::create('candidate_language', function (Blueprint $table) {
            $table->foreignId('candidate_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('level', 8);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->primary(['candidate_profile_id', 'language_id']);
        });

        Schema::create('user_availability_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('timezone')->default('Europe/Berlin');
            $table->timestamps();
            $table->index(['user_id', 'weekday']);
        });

        Schema::create('candidate_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_profile_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('title');
            $table->string('disk')->default('private');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable()->index();
            $table->string('status')->default('uploaded')->index();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('scan_completed_at')->nullable();
            $table->string('scan_result')->nullable();
            $table->boolean('shared_with_employers')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_documents');
        Schema::dropIfExists('user_availability_slots');
        Schema::dropIfExists('candidate_language');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('candidate_skill');
        Schema::dropIfExists('candidate_educations');
        Schema::dropIfExists('candidate_experiences');
        Schema::dropIfExists('candidate_profiles');
        Schema::dropIfExists('occupation_skill');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('occupations');
    }
};
