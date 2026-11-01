<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repository_questions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_revision_id')->constrained()->cascadeOnDelete();
            $table->char('revision_fingerprint', 64);
            $table->text('question');
            $table->string('requested_model', 100);
            $table->string('retrieval_method', 32);
            $table->string('status', 20)->default('queued');
            $table->text('answer')->nullable();
            $table->text('source_context')->nullable();
            $table->text('source_chunks')->nullable();
            $table->char('prompt_sha256', 64)->nullable();
            $table->unsignedInteger('estimated_prompt_tokens')->nullable();
            $table->unsignedInteger('actual_input_tokens')->nullable();
            $table->unsignedInteger('actual_output_tokens')->nullable();
            $table->unsignedBigInteger('inference_duration_ns')->nullable();
            $table->decimal('inference_wall_ms', 12, 3)->unsigned()->nullable();
            $table->string('completion_status', 20)->nullable();
            $table->string('stop_reason', 100)->nullable();
            $table->string('safe_error', 255)->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->char('request_sha256', 64);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'project_id', 'created_at']);
            $table->index(['status', 'heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_questions');
    }
};
