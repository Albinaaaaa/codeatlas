<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laravel_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->unsignedBigInteger('code_file_id')->nullable();
            $table->unsignedBigInteger('controller_symbol_id')->nullable();
            $table->string('method');
            $table->string('uri', 1024);
            $table->string('name')->nullable();
            $table->string('action')->nullable();
            $table->jsonb('middleware')->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id'],
                'laravel_routes_revision_fk',
            )->references(['project_id', 'id'])->on('project_revisions')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'code_file_id'],
                'laravel_routes_file_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_files')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'controller_symbol_id'],
                'laravel_routes_controller_symbol_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_symbols')->cascadeOnDelete();
            $table->index(
                ['project_id', 'project_revision_id', 'method'],
                'laravel_routes_revision_method_index',
            );
            $table->index(
                ['project_id', 'project_revision_id', 'name'],
                'laravel_routes_revision_name_index',
            );
        });

        Schema::create('laravel_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('code_symbol_id');
            $table->string('table_name')->nullable();
            $table->string('connection')->nullable();
            $table->jsonb('traits')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id', 'code_symbol_id'],
                'laravel_models_symbol_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_symbols')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'project_revision_id', 'id'],
                'laravel_models_project_revision_id_unique',
            );
            $table->unique(
                ['project_id', 'project_revision_id', 'code_symbol_id'],
                'laravel_models_revision_symbol_unique',
            );
            $table->index(
                ['project_id', 'project_revision_id', 'table_name'],
                'laravel_models_revision_table_index',
            );
        });

        Schema::create('laravel_model_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('laravel_model_id');
            $table->unsignedBigInteger('related_laravel_model_id')->nullable();
            $table->unsignedBigInteger('code_file_id')->nullable();
            $table->string('name');
            $table->string('relation_type');
            $table->string('related_model');
            $table->string('foreign_key')->nullable();
            $table->string('local_key')->nullable();
            $table->string('pivot_table')->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id', 'laravel_model_id'],
                'laravel_model_relations_model_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('laravel_models')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'related_laravel_model_id'],
                'laravel_model_relations_related_model_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('laravel_models')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'code_file_id'],
                'laravel_model_relations_file_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_files')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'project_revision_id', 'laravel_model_id', 'name'],
                'laravel_model_relations_model_name_unique',
            );
            $table->index(
                ['project_id', 'project_revision_id', 'related_laravel_model_id'],
                'laravel_model_relations_related_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laravel_model_relations');
        Schema::dropIfExists('laravel_models');
        Schema::dropIfExists('laravel_routes');
    }
};
