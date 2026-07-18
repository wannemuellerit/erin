<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_message_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('source', 30)->default('erin');
            $table->string('external_id', 120)->nullable();
            $table->string('disk', 40)->nullable();
            $table->string('path', 1024)->nullable();
            $table->string('original_name');
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('scan_result', 30)->default('pending')->index();
            $table->timestamp('scan_completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['support_ticket_message_id', 'external_id'],
                'support_attachment_message_external_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');
    }
};
