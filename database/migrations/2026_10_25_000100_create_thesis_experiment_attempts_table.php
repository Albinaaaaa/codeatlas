<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thesis_experiment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->char('run_key', 64);
            $table->uuid('attempt_id')->unique();
            $table->unsignedInteger('repetition_index');
            $table->string('dataset_id', 128);
            $table->string('dataset_version', 32);
            $table->char('dataset_sha256', 64);
            $table->string('split', 16);
            $table->string('question_id', 128);
            $table->char('revision_fingerprint', 64);
            $table->string('protocol_version', 32);
            $table->string('method', 32);
            $table->string('requested_model', 64);
            $table->string('returned_model', 64)->nullable();
            $table->string('prompt_version', 32);
            $table->char('prompt_sha256', 64);
            $table->string('status', 16);
            $table->longText('payload');
            $table->timestamps();
            $table->foreign(['project_id', 'project_revision_id'], 'thesis_attempts_revision_fk')
                ->references(['project_id', 'id'])->on('project_revisions')->restrictOnDelete();
            $table->index(['project_id', 'run_key', 'repetition_index'], 'thesis_attempts_run_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thesis_experiment_attempts');
    }
};
