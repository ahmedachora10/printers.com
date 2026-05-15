<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('product_units')->cascadeOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('name', 255);
            $table->decimal('cost_price', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->integer('min_stock_level')->default(0);
            $table->integer('current_stock')->default(0);
            $table->string('barcode', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['sku', 'branch_id', 'unit_id', 'name', 'is_active'], 'products_sku_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
