<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thesis_experiment_attempts', function (Blueprint $table): void {
            $table->char('experiment_key', 64)->nullable()->index();
            $table->char('case_key', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('thesis_experiment_attempts', function (Blueprint $table): void {
            $table->dropColumn(['experiment_key', 'case_key']);
        });
    }
};
