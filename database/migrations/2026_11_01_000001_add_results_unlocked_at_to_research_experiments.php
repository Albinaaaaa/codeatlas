<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_experiments', function (Blueprint $table): void {
            $table->timestampTz('results_unlocked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('research_experiments', function (Blueprint $table): void {
            $table->dropColumn('results_unlocked_at');
        });
    }
};
