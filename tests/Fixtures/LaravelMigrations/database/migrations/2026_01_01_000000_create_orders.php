<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->string('tenant_id');
            $table->string('region');
            $table->string('status')->default('new')->nullable();
            $table->unique(['tenant_id', 'number'], 'orders_tenant_number_unique');
            $table->index(['status', 'number'], 'orders_status_number_index');
            $table->foreign(['tenant_id', 'region'], 'orders_tenant_region_foreign')
                ->references(['tenant_id', 'region'])->on('tenants')->cascadeOnDelete();
        });
    }
};
