<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 47 (وهو التاسك 30 نفسه): دليل الأسعار ببيانات مستقلة لكل فرع.
 *
 * الشجرة (الفئات والفئات الفرعية) تبقى عامة — فرعٌ لا يخترع فئاته — والسعر وحده
 * صار مملوكاً: `branch_id = NULL` سعر عام يراه كل فرع (وهو حال كل الصفوف
 * القائمة فلا تتأثر البيانات)، و`branch_id` مملوءاً سعرُ فرعٍ بعينه يعلو العام
 * حين يتشاركان الاسم داخل الفئة الفرعية نفسها.
 *
 * التفرّد انتقل من (subcategory_id, name) إلى (subcategory_id, branch_id, name)
 * حتى يتعايش سعر الفرع مع العام. تنبيه: MySQL وSQLite يعتبران NULL قيماً
 * متمايزة، فهذا الفهرس لا يمنع تكرار سعرَين عامَّين بالاسم نفسه — التفرّد على
 * الأسعار العامة محروس في Form Request بقاعدة whereNull('branch_id').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_prices', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('subcategory_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->dropUnique(['subcategory_id', 'name']);
            $table->unique(['subcategory_id', 'branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_prices', function (Blueprint $table) {
            $table->dropUnique(['subcategory_id', 'branch_id', 'name']);
            $table->dropConstrainedForeignId('branch_id');
            $table->unique(['subcategory_id', 'name']);
        });
    }
};
