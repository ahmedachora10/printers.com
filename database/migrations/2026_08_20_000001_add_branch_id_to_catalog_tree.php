<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 47 — التوسعة: شجرة دليل كاملة لكل فرع.
 *
 * الخطوة الأولى ملّكت السعر وحده لفرعه (`catalog_prices.branch_id`). وهذه تُكمل
 * الاستقلال إلى أعلى الشجرة: الفئة والخدمة الفرعية صار لكل منهما مالك.
 * `branch_id = NULL` صفٌّ عام يراه كل الفروع — وهو حال كل الصفوف القائمة فلم
 * تتأثر بيانة — و`branch_id` مملوءاً صفٌّ أنشأه مدير فرع فلا يظهر إلا في فرعه
 * وعند السوبر أدمن.
 *
 * الشجرة **تجميعية لا مُلغِية**: الفرع يرى العام + ما أضافه هو، ولا يُخفي صفّاً
 * عاماً. الإلغاء الوحيد في الدليل هو إلغاء **السعر** بالاسم داخل الخدمة
 * الفرعية، وهو المكان الذي يهمّ فيه المال فعلاً.
 *
 * لا فهرس تفرّد على الأسماء هنا: الجدولان لم يحملا واحداً أصلاً، وقد تكون في
 * الإنتاج أسماء مكرّرة قائمة — فالتفرّد داخل النطاق محروس في Form Requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->index(['branch_id', 'is_active', 'sort_order']);
        });

        Schema::table('catalog_subcategories', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->index(['branch_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_subcategories', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'is_active', 'sort_order']);
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'is_active', 'sort_order']);
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
