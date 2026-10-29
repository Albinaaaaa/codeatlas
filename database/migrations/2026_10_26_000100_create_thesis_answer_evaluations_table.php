<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thesis_answer_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thesis_experiment_attempt_id')->unique()->constrained()->restrictOnDelete();
            $table->char('evaluation_id', 64)->unique();
            $table->string('rubric_version', 32);
            $table->unsignedTinyInteger('correctness');
            $table->longText('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thesis_answer_evaluations');
    }
};
