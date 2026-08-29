<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->string('path', 1024);
            $table->string('language')->nullable();
            $table->string('content_hash', 128);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('line_count')->nullable();
            $table->boolean('is_generated')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id'],
                'code_files_revision_fk',
            )->references(['project_id', 'id'])->on('project_revisions')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'project_revision_id', 'id'],
                'code_files_project_revision_id_unique',
            );
            $table->unique(
                ['project_id', 'project_revision_id', 'path'],
                'code_files_revision_path_unique',
            );
            $table->index(
                ['project_id', 'project_revision_id', 'language'],
                'code_files_revision_language_index',
            );
        });

        Schema::create('code_symbols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('code_file_id');
            $table->unsignedBigInteger('parent_symbol_id')->nullable();
            $table->string('kind');
            $table->string('name');
            $table->string('qualified_name')->nullable();
            $table->text('signature')->nullable();
            $table->string('visibility')->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->unsignedInteger('start_column')->nullable();
            $table->unsignedInteger('end_column')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id', 'code_file_id'],
                'code_symbols_file_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_files')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'project_revision_id', 'id'],
                'code_symbols_project_revision_id_unique',
            );
            $table->foreign(
                ['project_id', 'project_revision_id', 'parent_symbol_id'],
                'code_symbols_parent_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_symbols')->cascadeOnDelete();
            $table->index(
                ['project_id', 'project_revision_id', 'qualified_name'],
                'code_symbols_revision_qualified_name_index',
            );
            $table->index(
                ['project_id', 'project_revision_id', 'kind'],
                'code_symbols_revision_kind_index',
            );
        });

        Schema::create('code_symbol_roles', function (Blueprint $table) {
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('code_symbol_id');
            $table->string('role');
            $table->timestamps();

            $table->primary(
                ['project_id', 'project_revision_id', 'code_symbol_id', 'role'],
                'code_symbol_roles_primary',
            );
            $table->foreign(
                ['project_id', 'project_revision_id', 'code_symbol_id'],
                'code_symbol_roles_symbol_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_symbols')->cascadeOnDelete();
            $table->index(
                ['project_id', 'project_revision_id', 'role'],
                'code_symbol_roles_revision_role_index',
            );
        });

        Schema::create('code_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('from_symbol_id');
            $table->unsignedBigInteger('to_symbol_id')->nullable();
            $table->unsignedBigInteger('code_file_id')->nullable();
            $table->string('type');
            $table->string('target_name')->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id', 'from_symbol_id'],
                'code_relations_from_symbol_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_symbols')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'to_symbol_id'],
                'code_relations_to_symbol_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_symbols')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'code_file_id'],
                'code_relations_file_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_files')->cascadeOnDelete();
            $table->index(
                ['project_id', 'project_revision_id', 'from_symbol_id', 'type'],
                'code_relations_from_type_index',
            );
            $table->index(
                ['project_id', 'project_revision_id', 'to_symbol_id'],
                'code_relations_to_symbol_index',
            );
        });

        Schema::create('analysis_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->unsignedBigInteger('index_run_id')->nullable();
            $table->unsignedBigInteger('code_file_id')->nullable();
            $table->unsignedBigInteger('code_symbol_id')->nullable();
            $table->string('severity');
            $table->string('category');
            $table->string('code')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_path', 1024)->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id'],
                'analysis_issues_revision_fk',
            )->references(['project_id', 'id'])->on('project_revisions')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'index_run_id'],
                'analysis_issues_run_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('index_runs')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'code_file_id'],
                'analysis_issues_file_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_files')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'code_symbol_id'],
                'analysis_issues_symbol_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_symbols')->cascadeOnDelete();
            $table->index(
                ['project_id', 'project_revision_id', 'severity'],
                'analysis_issues_revision_severity_index',
            );
            $table->index(
                ['project_id', 'project_revision_id', 'index_run_id'],
                'analysis_issues_run_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_issues');
        Schema::dropIfExists('code_relations');
        Schema::dropIfExists('code_symbol_roles');
        Schema::dropIfExists('code_symbols');
        Schema::dropIfExists('code_files');
    }
};
