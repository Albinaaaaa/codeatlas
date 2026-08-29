<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->string('schema_name')->default('public');
            $table->string('name');
            $table->string('type')->default('table');
            $table->text('comment')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id'],
                'database_tables_revision_fk',
            )->references(['project_id', 'id'])->on('project_revisions')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'project_revision_id', 'id'],
                'database_tables_project_revision_id_unique',
            );
            $table->unique(
                ['project_id', 'project_revision_id', 'schema_name', 'name'],
                'database_tables_revision_schema_name_unique',
            );
        });

        Schema::create('database_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('database_table_id');
            $table->string('name');
            $table->unsignedInteger('ordinal_position');
            $table->string('data_type');
            $table->string('native_type')->nullable();
            $table->boolean('is_nullable')->default(false);
            $table->text('default_value')->nullable();
            $table->text('generated_expression')->nullable();
            $table->text('comment')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id', 'database_table_id'],
                'database_columns_table_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('database_tables')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'project_revision_id', 'id'],
                'database_columns_project_revision_id_unique',
            );
            $table->unique(
                ['project_id', 'project_revision_id', 'database_table_id', 'name'],
                'database_columns_table_name_unique',
            );
            $table->unique(
                ['project_id', 'project_revision_id', 'database_table_id', 'ordinal_position'],
                'database_columns_table_position_unique',
            );
        });

        Schema::create('database_foreign_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('database_table_id');
            $table->unsignedBigInteger('referenced_database_table_id')->nullable();
            $table->string('name');
            $table->string('referenced_schema_name')->nullable();
            $table->string('referenced_table_name');
            $table->string('on_update')->nullable();
            $table->string('on_delete')->nullable();
            $table->boolean('is_deferrable')->default(false);
            $table->boolean('is_initially_deferred')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id', 'database_table_id'],
                'database_foreign_keys_table_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('database_tables')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'referenced_database_table_id'],
                'database_foreign_keys_referenced_table_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('database_tables')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'project_revision_id', 'id'],
                'database_foreign_keys_project_revision_id_unique',
            );
            $table->unique(
                ['project_id', 'project_revision_id', 'database_table_id', 'name'],
                'database_foreign_keys_table_name_unique',
            );
            $table->index(
                ['project_id', 'project_revision_id', 'referenced_database_table_id'],
                'database_foreign_keys_referenced_table_index',
            );
        });

        Schema::create('database_foreign_key_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('database_foreign_key_id');
            $table->foreignId('database_column_id');
            $table->unsignedBigInteger('referenced_database_column_id')->nullable();
            $table->unsignedInteger('ordinal_position');
            $table->string('referenced_column_name');
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id', 'database_foreign_key_id'],
                'database_fk_columns_foreign_key_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('database_foreign_keys')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'database_column_id'],
                'database_fk_columns_column_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('database_columns')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'referenced_database_column_id'],
                'database_fk_columns_referenced_column_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('database_columns')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'project_revision_id', 'database_foreign_key_id', 'ordinal_position'],
                'database_fk_columns_foreign_key_position_unique',
            );
        });

        Schema::create('database_indexes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('database_table_id');
            $table->string('name');
            $table->string('method')->nullable();
            $table->boolean('is_unique')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->text('predicate')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id', 'database_table_id'],
                'database_indexes_table_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('database_tables')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'project_revision_id', 'id'],
                'database_indexes_project_revision_id_unique',
            );
            $table->unique(
                ['project_id', 'project_revision_id', 'database_table_id', 'name'],
                'database_indexes_table_name_unique',
            );
        });

        Schema::create('database_index_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('database_index_id');
            $table->unsignedBigInteger('database_column_id')->nullable();
            $table->unsignedInteger('ordinal_position');
            $table->text('expression')->nullable();
            $table->string('direction')->nullable();
            $table->string('nulls_order')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id', 'database_index_id'],
                'database_index_columns_index_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('database_indexes')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'database_column_id'],
                'database_index_columns_column_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('database_columns')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'project_revision_id', 'database_index_id', 'ordinal_position'],
                'database_index_columns_index_position_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_index_columns');
        Schema::dropIfExists('database_indexes');
        Schema::dropIfExists('database_foreign_key_columns');
        Schema::dropIfExists('database_foreign_keys');
        Schema::dropIfExists('database_columns');
        Schema::dropIfExists('database_tables');
    }
};
