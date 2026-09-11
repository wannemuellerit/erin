<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_cases', function (Blueprint $table): void {
            $table->json('service_details')->nullable()->after('requested_services');
        });

        Schema::table('partner_case_artifacts', function (Blueprint $table): void {
            $table->foreignId('source_document_id')->nullable()->after('created_by')->constrained('candidate_documents')->nullOnDelete();
            $table->char('source_checksum', 64)->nullable()->after('checksum');
            $table->unique(['partner_case_id', 'kind', 'version'], 'partner_case_artifact_unique_version');
        });

        Schema::table('partner_document_grants', function (Blueprint $table): void {
            $table->char('document_sha256', 64)->nullable()->after('purpose');
        });

        Schema::create('partner_case_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->string('title');
            $table->string('status', 24)->default('open');
            $table->string('idempotency_key', 64)->unique();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['partner_case_id', 'status']);
            $table->index(['assigned_to', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_case_tasks');

        Schema::table('partner_document_grants', function (Blueprint $table): void {
            $table->dropColumn('document_sha256');
        });

        Schema::table('partner_case_artifacts', function (Blueprint $table): void {
            $table->dropUnique('partner_case_artifact_unique_version');
            $table->dropConstrainedForeignId('source_document_id');
            $table->dropColumn('source_checksum');
        });

        Schema::table('partner_cases', function (Blueprint $table): void {
            $table->dropColumn('service_details');
        });
    }
};
