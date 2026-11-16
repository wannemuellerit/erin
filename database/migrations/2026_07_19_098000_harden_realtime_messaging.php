<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->uuid('client_id')->nullable()->after('sender_id');
            $table->unique(['sender_id', 'client_id']);
        });

        Schema::table('message_attachments', function (Blueprint $table): void {
            $table->json('waveform')->nullable()->after('duration_seconds');
        });

        Schema::create('message_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->string('target_locale', 10);
            $table->string('status')->default('pending');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->longText('translated_body')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'target_locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_translations');
        Schema::table('message_attachments', fn (Blueprint $table) => $table->dropColumn('waveform'));
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropUnique(['sender_id', 'client_id']);
            $table->dropColumn('client_id');
        });
    }
};
