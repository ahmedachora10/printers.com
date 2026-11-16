<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 237 — فئة المنتج لفرعٍ أو عدّة فروع. بلا صفوف = عامة؛ كل الفئات القائمة تبقى
 * عامة حتى يحصرها السوبر أدمن يدوياً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_product_category', function (Blueprint $table) {
            $table->foreignId('product_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_category_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_product_category');
    }
};
