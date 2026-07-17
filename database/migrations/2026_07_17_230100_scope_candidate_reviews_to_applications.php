<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_internal_reviews', function (Blueprint $table) {
            // MySQL may use the old composite unique index for the company FK.
            // Keep a temporary supporting index while replacing it.
            $table->index('company_id', 'candidate_internal_reviews_company_fk_swap');
            $table->dropUnique('candidate_internal_review_unique');
            $table->unique(
                ['company_id', 'application_id', 'reviewer_id'],
                'candidate_internal_review_application_unique',
            );
            $table->dropIndex('candidate_internal_reviews_company_fk_swap');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_internal_reviews', function (Blueprint $table) {
            $table->index('company_id', 'candidate_internal_reviews_company_fk_swap');
            $table->dropUnique('candidate_internal_review_application_unique');
            $table->unique(
                ['company_id', 'candidate_profile_id', 'reviewer_id'],
                'candidate_internal_review_unique',
            );
            $table->dropIndex('candidate_internal_reviews_company_fk_swap');
        });
    }
};
