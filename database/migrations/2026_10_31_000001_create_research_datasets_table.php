<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_datasets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_revision_id')->constrained()->restrictOnDelete();
            $table->string('dataset_id', 128);
            $table->string('dataset_version', 32);
            $table->string('split', 16);
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('lock_version')->default(1);
            $table->text('questions');
            $table->json('validation_report')->nullable();
            $table->string('original_path')->nullable();
            $table->char('original_sha256', 64)->nullable();
            $table->string('frozen_path')->nullable();
            $table->char('frozen_sha256', 64)->nullable();
            $table->timestampTz('frozen_at')->nullable();
            $table->timestampsTz();
            $table->index(['project_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_datasets');
    }
};
