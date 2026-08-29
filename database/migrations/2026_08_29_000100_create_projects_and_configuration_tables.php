<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('user_id', 'projects_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'slug'], 'projects_owner_slug_unique');
            $table->unique(['user_id', 'id'], 'projects_owner_id_unique');
        });

        Schema::create('project_profiles', function (Blueprint $table) {
            $table->foreignId('project_id')->primary();
            $table->text('summary')->nullable();
            $table->text('architecture')->nullable();
            $table->string('primary_language')->nullable();
            $table->string('framework')->nullable();
            $table->string('framework_version')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('project_id', 'project_profiles_project_fk')
                ->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('project_settings', function (Blueprint $table) {
            $table->foreignId('project_id')->primary();
            $table->jsonb('included_paths')->nullable();
            $table->jsonb('excluded_paths')->nullable();
            $table->boolean('index_vendor')->default(false);
            $table->boolean('index_tests')->default(true);
            $table->unsignedBigInteger('max_file_size')->default(1_048_576);
            $table->timestamps();

            $table->foreign('project_id', 'project_settings_project_fk')
                ->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('project_technologies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->string('name');
            $table->string('category');
            $table->string('version')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('project_id', 'project_technologies_project_fk')
                ->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(
                ['project_id', 'category', 'name'],
                'project_technologies_project_category_name_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_technologies');
        Schema::dropIfExists('project_settings');
        Schema::dropIfExists('project_profiles');
        Schema::dropIfExists('projects');
    }
};
