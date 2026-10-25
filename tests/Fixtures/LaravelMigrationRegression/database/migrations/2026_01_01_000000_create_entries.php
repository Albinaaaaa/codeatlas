<?php

namespace Fixtures\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEntries extends Migration
{
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grid_id')->constrained();
            $table->foreignId('category_id')->constrained();
            $table->foreignId('person_id')->constrained();
            $table->foreignId('owner_id')->constrained('accounts');
            $table->foreignId('editor_id')->constrained(table: 'users');
            $table->foreignId('account_key')->constrained(column: 'key', indexName: 'entry_account_fk');
            $table->foreignId('dynamic_id')->constrained(resolveTable());
            $table->foreignId('unknown_id')->constrained(column: resolveColumn());
            $deferred = function (): void {
                Schema::create('nested', function (Blueprint $table): void {
                    $table->id();
                });
            };
        });
        Schema::table('entries', function (Blueprint $table): void {
            $table->string('title')->nullable()->index();
        });
        if (shouldCreateTable()) {
            Schema::create('conditional', function (Blueprint $table): void {
                $table->id();
            });
        }
    }

    public function down(): void
    {
        Schema::create('entries', function (Blueprint $table): void {
            $table->id();
        });
        Schema::table('entries', function (Blueprint $table): void {
            $table->string('rollback_only');
        });
        Schema::create('rollback_table', function (Blueprint $table): void {
            $table->id();
        });
    }

    private function helper(): void
    {
        Schema::create('helper_table', function (Blueprint $table): void {
            $table->id();
        });
    }
}
