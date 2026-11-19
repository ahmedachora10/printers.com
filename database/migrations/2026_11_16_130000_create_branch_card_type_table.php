<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 243 — نوع البطاقة لفرعٍ أو عدّة فروع. بلا صفوف = عام؛ الأنواع القائمة تبقى عامة
 * حتى يحصرها السوبر أدمن يدوياً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_card_type', function (Blueprint $table) {
            $table->foreignId('card_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->primary(['card_type_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_card_type');
    }
};
