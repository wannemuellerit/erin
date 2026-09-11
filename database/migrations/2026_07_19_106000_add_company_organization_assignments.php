<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_invitations', function (Blueprint $table): void {
            $table->timestamp('revoked_at')->nullable()->after('accepted_at')->index();
        });
        Schema::table('company_memberships', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('invited_by')
                ->constrained('company_locations')->nullOnDelete();
        });
        Schema::table('company_teams', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('name')
                ->constrained('company_locations')->nullOnDelete();
            $table->foreignId('contact_membership_id')->nullable()->after('location_id')
                ->constrained('company_memberships')->nullOnDelete();
        });
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->foreignId('company_team_id')->nullable()->after('location_id')
                ->constrained('company_teams')->nullOnDelete();
            $table->foreignId('contact_membership_id')->nullable()->after('company_team_id')
                ->constrained('company_memberships')->nullOnDelete();
        });
        Schema::table('applications', function (Blueprint $table): void {
            $table->foreignId('company_team_id')->nullable()->after('candidate_profile_id')
                ->constrained('company_teams')->nullOnDelete();
            $table->foreignId('contact_membership_id')->nullable()->after('company_team_id')
                ->constrained('company_memberships')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contact_membership_id');
            $table->dropConstrainedForeignId('company_team_id');
        });
        Schema::table('job_postings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contact_membership_id');
            $table->dropConstrainedForeignId('company_team_id');
        });
        Schema::table('company_teams', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contact_membership_id');
            $table->dropConstrainedForeignId('location_id');
        });
        Schema::table('company_memberships', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
        });
        Schema::table('company_invitations', function (Blueprint $table): void {
            $table->dropColumn('revoked_at');
        });
    }
};
