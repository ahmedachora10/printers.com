<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcategory_id')->constrained('catalog_subcategories')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('min_price', 12, 2);
            $table->decimal('max_price', 12, 2);
            $table->decimal('base_price', 12, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['subcategory_id', 'name']);
            $table->index(['subcategory_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_prices');
    }
};
