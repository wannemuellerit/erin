<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_score_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 40)->unique();
            $table->json('weights');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('activation_reason')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_score_versions');
    }
};
