<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL commits DDL statements individually. The outer guard makes the
        // migration safe to resume after a later ALTER TABLE fails.
        if (! Schema::hasTable('country_service_rules')) {
            Schema::create('country_service_rules', function (Blueprint $table): void {
                $table->id();
                $table->char('country_code', 2);
                $table->string('service_type', 48);
                $table->string('status', 24)->default('disabled');
                $table->char('currency_code', 3)->nullable();
                $table->string('tax_model')->nullable();
                $table->string('legal_basis')->nullable();
                $table->json('requirements')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['country_code', 'service_type', 'version'], 'country_service_rule_version_unique');
                $table->index(['country_code', 'service_type', 'status'], 'country_service_rule_lookup');
            });

            Schema::create('partner_organizations', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('category', 48);
                $table->json('country_codes')->nullable();
                $table->json('service_types')->nullable();
                $table->json('languages')->nullable();
                $table->string('integration_mode', 24)->default('manual');
                $table->string('contract_status', 24)->default('pending');
                $table->string('dpa_status', 24)->default('pending');
                $table->timestamp('legal_approved_at')->nullable();
                $table->timestamp('logo_approved_at')->nullable();
                $table->timestamp('blocked_at')->nullable();
                $table->text('blocked_reason')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['category', 'blocked_at']);
            });

            Schema::create('partner_members', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('partner_organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('role', 24)->default('case_worker');
                $table->json('capabilities')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->unique(['partner_organization_id', 'user_id'], 'partner_member_unique');
            });

            Schema::create('partner_offerings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('partner_organization_id')->constrained()->cascadeOnDelete();
                $table->string('service_type', 48);
                $table->char('country_code', 2);
                $table->string('title');
                $table->text('description')->nullable();
                $table->json('attributes')->nullable();
                $table->char('currency_code', 3)->nullable();
                $table->unsignedBigInteger('price_minor')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->boolean('is_active')->default(false);
                $table->timestamps();
                $table->index(['service_type', 'country_code', 'is_active'], 'partner_offering_catalog');
            });

            Schema::create('partner_cases', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->string('service_type', 48);
                $table->foreignId('candidate_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('partner_organization_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('partner_offering_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('assigned_member_id')->nullable()->constrained('partner_members')->nullOnDelete();
                $table->char('target_country_code', 2);
                $table->string('status', 32)->default('draft');
                $table->string('public_status', 32)->default('draft');
                $table->string('purpose');
                $table->json('requested_services')->nullable();
                $table->json('shared_data_categories')->nullable();
                $table->timestamp('consented_at')->nullable();
                $table->timestamp('consent_expires_at')->nullable();
                $table->timestamp('withdrawn_at')->nullable();
                $table->string('external_reference')->nullable();
                $table->string('correlation_id')->nullable();
                $table->timestamp('retention_until')->nullable();
                $table->timestamps();
                $table->index(['candidate_user_id', 'service_type', 'status']);
                $table->index(['partner_organization_id', 'status']);
            });

            Schema::create('partner_case_artifacts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('partner_case_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('kind', 48);
                $table->string('title');
                $table->longText('encrypted_payload')->nullable();
                $table->string('checksum', 64)->nullable();
                $table->string('status', 32)->default('draft');
                $table->string('authority')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('valid_until')->nullable();
                $table->timestamps();
                $table->index(['partner_case_id', 'kind', 'version'], 'partner_case_artifact_version');
            });

            Schema::create('partner_document_grants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('partner_case_id')->constrained()->cascadeOnDelete();
                $table->foreignId('candidate_document_id')->constrained()->cascadeOnDelete();
                $table->foreignId('partner_organization_id')->constrained()->cascadeOnDelete();
                $table->string('purpose');
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedInteger('download_count')->default(0);
                $table->timestamp('last_downloaded_at')->nullable();
                $table->timestamps();
                $table->unique(['partner_case_id', 'candidate_document_id', 'partner_organization_id'], 'partner_document_grant_unique');
            });

            Schema::create('partner_case_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('partner_case_id')->constrained()->cascadeOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event_type', 64);
                $table->string('summary');
                $table->json('metadata')->nullable();
                $table->boolean('visible_to_candidate')->default(true);
                $table->boolean('visible_to_company')->default(false);
                $table->timestamps();
                $table->index(['partner_case_id', 'created_at']);
            });

            Schema::create('partner_integration_receipts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('partner_organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('partner_case_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provider', 64);
                $table->string('direction', 16);
                $table->string('idempotency_key');
                $table->string('payload_hash', 64);
                $table->boolean('signature_valid')->default(false);
                $table->string('status', 32);
                $table->unsignedInteger('attempts')->default(1);
                $table->text('failure_code')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->unique(['partner_organization_id', 'provider', 'idempotency_key'], 'partner_receipt_idempotency');
            });
        }

        if (! Schema::hasColumn('companies', 'registered_country_code')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->char('registered_country_code', 2)->nullable()->after('slug');
                $table->char('default_target_country_code', 2)->nullable()->after('registered_country_code');
            });
        }

        if (! Schema::hasColumn('job_postings', 'target_country_code')) {
            Schema::table('job_postings', function (Blueprint $table): void {
                $table->char('target_country_code', 2)->nullable()->after('location_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('job_postings', fn (Blueprint $table) => $table->dropColumn('target_country_code'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['registered_country_code', 'default_target_country_code']));
        Schema::dropIfExists('partner_integration_receipts');
        Schema::dropIfExists('partner_case_events');
        Schema::dropIfExists('partner_document_grants');
        Schema::dropIfExists('partner_case_artifacts');
        Schema::dropIfExists('partner_cases');
        Schema::dropIfExists('partner_offerings');
        Schema::dropIfExists('partner_members');
        Schema::dropIfExists('partner_organizations');
        Schema::dropIfExists('country_service_rules');
    }
};
