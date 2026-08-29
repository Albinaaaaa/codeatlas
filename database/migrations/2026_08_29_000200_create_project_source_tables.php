<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->string('type');
            $table->string('name');
            $table->text('repository_url')->nullable();
            $table->string('default_branch')->nullable();
            $table->string('external_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('project_id', 'project_sources_project_fk')
                ->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'id'], 'project_sources_project_id_unique');
            $table->unique(['project_id', 'name'], 'project_sources_project_name_unique');
            $table->index(['project_id', 'type'], 'project_sources_project_type_index');
        });

        Schema::create('local_project_sources', function (Blueprint $table) {
            $table->foreignId('project_id');
            $table->foreignId('project_source_id')->primary();
            $table->text('path');
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_source_id'],
                'local_project_sources_source_fk',
            )->references(['project_id', 'id'])->on('project_sources')->cascadeOnDelete();
            $table->unique(['project_id', 'path'], 'local_project_sources_project_path_unique');
        });

        Schema::create('project_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_source_id');
            $table->string('identifier');
            $table->string('branch')->nullable();
            $table->string('commit_hash', 64)->nullable();
            $table->text('message')->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_source_id'],
                'project_revisions_source_fk',
            )->references(['project_id', 'id'])->on('project_sources')->cascadeOnDelete();
            $table->unique(['project_id', 'id'], 'project_revisions_project_id_unique');
            $table->unique(
                ['project_id', 'project_source_id', 'identifier'],
                'project_revisions_source_identifier_unique',
            );
            $table->index(
                ['project_id', 'project_source_id', 'committed_at'],
                'project_revisions_source_committed_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_revisions');
        Schema::dropIfExists('local_project_sources');
        Schema::dropIfExists('project_sources');
    }
};
