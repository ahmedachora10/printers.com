<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('stock_reconciliations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->integer('system_qty');
            $table->integer('physical_qty');
            $table->integer('variance');
            $table->foreignId('movement_id')->nullable()->constrained('stock_movements');
            $table->timestamps();

            $table->index('reconciliation_id');
            $table->unique(['reconciliation_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reconciliation_lines');
    }
};
