<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visa_cases', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(0)->after('progress');
            $table->text('blocked_reason')->nullable()->after('notes');
            $table->text('closed_reason')->nullable()->after('blocked_reason');
            $table->string('credit_status', 24)->default('legacy_unknown')->after('closed_reason');
            $table->string('credit_source', 24)->nullable()->after('credit_status');
            $table->foreignId('credit_usage_period_id')->nullable()->after('credit_source')->constrained('company_usage_periods')->nullOnDelete();
            $table->foreignId('credit_ledger_id')->nullable()->after('credit_usage_period_id')->constrained('entitlement_ledgers')->nullOnDelete();
        });

        DB::table('visa_cases')->orderBy('id')->each(function (object $case): void {
            $ledger = DB::table('entitlement_ledgers')
                ->where('company_id', $case->company_id)
                ->where('resource', 'visa')
                ->where('source', 'visa_case')
                ->where('reference_type', 'visa_case')
                ->where('reference_id', $case->id)
                ->first();
            if ($ledger !== null) {
                DB::table('visa_cases')->where('id', $case->id)->update([
                    'credit_status' => 'consumed',
                    'credit_source' => 'purchased',
                    'credit_ledger_id' => $ledger->id,
                ]);

                return;
            }

            $period = DB::table('company_usage_periods')
                ->where('company_id', $case->company_id)
                ->where('starts_at', '<=', $case->created_at)
                ->where('ends_at', '>=', $case->created_at)
                ->where('visa_credits_used', '>', 0)
                ->orderByDesc('starts_at')
                ->first();
            if ($period !== null) {
                DB::table('visa_cases')->where('id', $case->id)->update([
                    'credit_status' => 'consumed',
                    'credit_source' => 'included',
                    'credit_usage_period_id' => $period->id,
                ]);
            }
        });

        Schema::table('visa_steps', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('description');
            $table->text('blocker')->nullable()->after('notes');
            $table->text('completion_evidence')->nullable()->after('blocker');
            $table->string('visibility', 24)->default('shared')->after('completion_evidence');
        });

        Schema::create('visa_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visa_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->text('blocker')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->string('visibility', 24)->default('shared')->index();
            $table->date('due_at')->nullable()->index();
            $table->text('completion_evidence')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->index(['visa_step_id', 'status', 'due_at']);
        });

        Schema::create('visa_case_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visa_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visa_step_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('candidate_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose')->nullable();
            $table->string('visibility', 24)->default('shared')->index();
            $table->string('translation_status', 24)->default('not_required');
            $table->string('review_status', 24)->default('pending');
            $table->timestamps();

            $table->unique(['visa_case_id', 'candidate_document_id']);
        });

        Schema::create('visa_case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visa_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->string('visibility', 24)->default('shared')->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['visa_case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visa_case_events');
        Schema::dropIfExists('visa_case_documents');
        Schema::dropIfExists('visa_tasks');

        Schema::table('visa_steps', function (Blueprint $table): void {
            $table->dropColumn(['notes', 'blocker', 'completion_evidence', 'visibility']);
        });

        Schema::table('visa_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('credit_ledger_id');
            $table->dropConstrainedForeignId('credit_usage_period_id');
            $table->dropColumn([
                'version',
                'blocked_reason',
                'closed_reason',
                'credit_status',
                'credit_source',
            ]);
        });
    }
};
