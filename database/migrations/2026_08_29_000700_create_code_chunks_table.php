<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('code_file_id');
            $table->unsignedBigInteger('code_symbol_id')->nullable();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->unsignedInteger('token_count')->nullable();
            $table->string('embedding_model')->nullable();

            $table->vector('embedding', dimensions: 1024)->nullable();

            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id', 'code_file_id'],
                'code_chunks_file_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_files')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'code_symbol_id'],
                'code_chunks_symbol_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_symbols')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'project_revision_id', 'code_file_id', 'chunk_index'],
                'code_chunks_file_chunk_index_unique',
            );
            $table->index(
                ['project_id', 'project_revision_id', 'code_symbol_id'],
                'code_chunks_symbol_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_chunks');
    }
};
