<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedbacks', function (Blueprint $table): void {
            $table->string('subject_type')->nullable()->after('subject_company_id');
            $table->unsignedBigInteger('subject_key')->nullable()->after('subject_type');
        });

        DB::table('feedbacks')
            ->whereNotNull('subject_user_id')
            ->update([
                'subject_type' => 'user',
                'subject_key' => DB::raw('subject_user_id'),
            ]);
        DB::table('feedbacks')
            ->whereNotNull('subject_company_id')
            ->update([
                'subject_type' => 'company',
                'subject_key' => DB::raw('subject_company_id'),
            ]);

        Schema::table('feedbacks', function (Blueprint $table): void {
            $table->unique(
                ['author_id', 'application_id', 'subject_type', 'subject_key'],
                'feedback_unique_author_application_subject',
            );
        });

        Schema::table('moderation_cases', function (Blueprint $table): void {
            $table->string('priority')->default('normal')->after('severity');
            $table->json('evidence')->nullable()->after('resolution');
            $table->timestamp('escalated_at')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('moderation_cases', function (Blueprint $table): void {
            $table->dropColumn(['priority', 'evidence', 'escalated_at']);
        });

        Schema::table('feedbacks', function (Blueprint $table): void {
            $table->dropUnique('feedback_unique_author_application_subject');
            $table->dropColumn(['subject_type', 'subject_key']);
        });
    }
};
