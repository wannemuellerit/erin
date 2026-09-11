<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('interview_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('participant_identity');
            $table->timestamp('first_joined_at')->nullable();
            $table->timestamp('last_joined_at')->nullable();
            $table->timestamp('last_left_at')->nullable();
            $table->unsignedInteger('total_seconds')->default(0);
            $table->unsignedInteger('join_count')->default(0);
            $table->timestamps();
            $table->unique(['interview_id', 'participant_identity'], 'interview_attendance_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_attendances');
    }
};
