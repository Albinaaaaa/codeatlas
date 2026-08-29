<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('index_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->string('status');
            $table->string('trigger')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->jsonb('statistics')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id'],
                'index_runs_revision_fk',
            )->references(['project_id', 'id'])->on('project_revisions')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'project_revision_id', 'id'],
                'index_runs_project_revision_id_unique',
            );
            $table->index(
                ['project_id', 'status', 'created_at'],
                'index_runs_project_status_created_index',
            );
        });

        Schema::create('index_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('index_run_id');
            $table->string('name');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('records_processed')->default(0);
            $table->text('failure_reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id', 'index_run_id'],
                'index_run_steps_run_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('index_runs')->cascadeOnDelete();
            $table->unique(['project_id', 'index_run_id', 'name'], 'index_run_steps_run_name_unique');
            $table->index(
                ['project_id', 'project_revision_id', 'status'],
                'index_run_steps_revision_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_run_steps');
        Schema::dropIfExists('index_runs');
    }
};
