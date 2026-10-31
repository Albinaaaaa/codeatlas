<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_experiments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('research_dataset_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->char('dataset_sha256', 64);
            $table->json('revision_fingerprints');
            $table->char('experiment_identity', 64)->nullable()->unique();
            $table->char('configuration_identity', 64)->nullable();
            $table->unsignedSmallInteger('repetitions');
            $table->unsignedInteger('total_cases')->default(0);
            $table->unsignedInteger('preparation_total')->default(0);
            $table->unsignedInteger('preparation_completed')->default(0);
            $table->unsignedInteger('attempted_cases')->default(0);
            $table->unsignedInteger('completed_cases')->default(0);
            $table->unsignedInteger('failed_cases')->default(0);
            $table->unsignedInteger('incomplete_cases')->default(0);
            $table->unsignedInteger('pending_cases')->default(0);
            $table->unsignedInteger('skipped_cases')->default(0);
            $table->unsignedInteger('batch_number')->default(0);
            $table->unsignedTinyInteger('manual_retry_rounds')->default(0);
            $table->string('status', 24)->default('preparing');
            $table->text('safe_error')->nullable();
            $table->json('model_provenance')->nullable();
            $table->uuid('execution_identity')->nullable();
            $table->timestampTz('batch_claimed_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('heartbeat_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['project_id', 'status', 'id']);
            $table->index(['status', 'heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_experiments');
    }
};
