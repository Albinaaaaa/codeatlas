<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('user_id');
            $table->string('title')->nullable();
            $table->timestamps();

            $table->foreign(
                ['user_id', 'project_id'],
                'conversations_project_owner_fk',
            )->references(['user_id', 'id'])->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'id'], 'conversations_project_id_unique');
            $table->index(
                ['project_id', 'user_id', 'updated_at'],
                'conversations_project_user_updated_index',
            );
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('conversation_id');
            $table->string('role');
            $table->longText('content');
            $table->string('model')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'conversation_id'],
                'conversation_messages_conversation_fk',
            )->references(['project_id', 'id'])->on('conversations')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'id'],
                'conversation_messages_project_id_unique',
            );
            $table->index(
                ['project_id', 'conversation_id', 'created_at'],
                'conversation_messages_conversation_created_index',
            );
        });

        Schema::create('message_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('project_revision_id');
            $table->foreignId('conversation_message_id');
            $table->unsignedBigInteger('code_file_id')->nullable();
            $table->unsignedBigInteger('code_symbol_id')->nullable();
            $table->string('source_type');
            $table->string('label')->nullable();
            $table->string('source_path', 1024)->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->text('excerpt')->nullable();
            $table->decimal('relevance_score', 7, 6)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(
                ['project_id', 'project_revision_id'],
                'message_sources_revision_fk',
            )->references(['project_id', 'id'])->on('project_revisions')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'conversation_message_id'],
                'message_sources_message_fk',
            )->references(['project_id', 'id'])->on('conversation_messages')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'code_file_id'],
                'message_sources_file_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_files')->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'project_revision_id', 'code_symbol_id'],
                'message_sources_symbol_fk',
            )->references(['project_id', 'project_revision_id', 'id'])
                ->on('code_symbols')->cascadeOnDelete();
            $table->index(
                ['project_id', 'conversation_message_id'],
                'message_sources_message_index',
            );
            $table->index(
                ['project_id', 'project_revision_id', 'code_file_id'],
                'message_sources_file_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_sources');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
