<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_documents', function (Blueprint $table): void {
            $table->unsignedSmallInteger('version')->default(1)->after('title');
            $table->timestamp('replaced_at')->nullable()->after('verified_at');
        });

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->text('summary')->nullable()->after('position');
            $table->longText('responsibilities')->nullable()->after('description');
            $table->longText('requirements')->nullable()->after('responsibilities');
            $table->longText('benefits')->nullable()->after('requirements');
            $table->date('application_deadline')->nullable()->after('language_notes')->index();
            $table->date('start_date')->nullable()->after('application_deadline');
            $table->unsignedSmallInteger('vacancies')->default(1)->after('start_date');
            $table->string('contact_name')->nullable()->after('vacancies');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->boolean('salary_visible')->default(true)->after('compensation_interval');
        });

        Schema::table('job_media', function (Blueprint $table): void {
            $table->string('title')->nullable()->after('type');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('job_media', function (Blueprint $table): void {
            $table->dropColumn(['title', 'sort_order']);
        });

        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropIndex(['application_deadline']);
            $table->dropColumn([
                'summary',
                'responsibilities',
                'requirements',
                'benefits',
                'application_deadline',
                'start_date',
                'vacancies',
                'contact_name',
                'contact_email',
                'salary_visible',
            ]);
        });

        Schema::table('candidate_documents', function (Blueprint $table): void {
            $table->dropColumn(['version', 'replaced_at']);
        });
    }
};
