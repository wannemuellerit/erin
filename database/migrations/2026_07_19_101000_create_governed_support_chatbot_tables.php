<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('stable_key', 120);
            $table->unsignedInteger('version');
            $table->string('locale', 5);
            $table->string('title', 180);
            $table->longText('body');
            $table->string('source_url', 2048)->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('target_roles')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('support_knowledge_articles')->nullOnDelete();
            $table->timestamps();
            $table->unique(['stable_key', 'version', 'locale'], 'support_knowledge_version_unique');
            $table->index(['status', 'locale', 'published_at', 'expires_at'], 'support_knowledge_publication_index');
        });

        Schema::create('support_chat_prompts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->longText('instructions');
            $table->json('allowed_tools')->nullable();
            $table->json('safety_rules')->nullable();
            $table->string('model', 120)->nullable();
            $table->boolean('active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('support_chat_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale', 5)->default('de');
            $table->string('status', 24)->default('active');
            $table->string('current_route', 500)->nullable();
            $table->foreignId('handed_off_ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete();
            $table->string('handoff_key', 64)->nullable()->unique();
            $table->timestamp('last_activity_at');
            $table->timestamp('retention_expires_at');
            $table->timestamps();
            $table->index(['user_id', 'status', 'last_activity_at'], 'support_chat_user_status_index');
            $table->index(['company_id', 'status'], 'support_chat_company_status_index');
        });

        Schema::create('support_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('support_chat_session_id');
            $table->uuid('client_id')->nullable();
            $table->string('author', 16);
            $table->longText('body');
            $table->json('source_article_ids')->nullable();
            $table->boolean('escalation_required')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('provider', 80)->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('feedback', 16)->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('retention_expires_at');
            $table->timestamps();
            $table->foreign('support_chat_session_id')->references('id')->on('support_chat_sessions')->cascadeOnDelete();
            $table->unique(['support_chat_session_id', 'client_id'], 'support_chat_message_client_unique');
            $table->index(['author', 'created_at'], 'support_chat_message_metric_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_chat_messages');
        Schema::dropIfExists('support_chat_sessions');
        Schema::dropIfExists('support_chat_prompts');
        Schema::dropIfExists('support_knowledge_articles');
    }
};
