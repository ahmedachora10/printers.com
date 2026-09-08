<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 89 — الاعتماد كان يكتب فوق سطر الطلب: اسم الصنف الذي كتبه الموظف يصير
 * اسم المنتج، والكمية المطلوبة تصير المعتمدة، والسعر التقديري يصير التكلفة
 * المعتمدة. فما طُلب يُمحى لحظة القرار ولا نسخة منه في جدول ولا في سجلّ نشاط.
 *
 * الأعمدة القائمة تعني من الآن **ما طلبه الموظف** وحدها، والقرار يُكتب هنا —
 * الطلب واقعةٌ والقرار واقعةٌ أخرى، وشاشة العرض تضعهما جنباً إلى جنب.
 *
 * ⚠️ ما اعتُمد قبل هذه الهجرة فقد أصله بلا رجعة؛ لا شيء هنا يعيده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_lines', function (Blueprint $table) {
            $table->foreignId('approved_product_id')->nullable()->after('product_id')
                ->constrained('products')->nullOnDelete();
            $table->decimal('approved_qty', 12, 2)->nullable()->after('qty');
            $table->boolean('approved_is_sqm')->nullable()->after('is_sqm');
            $table->decimal('approved_unit_cost', 12, 2)->nullable()->after('estimated_unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_lines', function (Blueprint $table) {
            $table->dropForeign(['approved_product_id']);
            $table->dropColumn(['approved_product_id', 'approved_qty', 'approved_is_sqm', 'approved_unit_cost']);
        });
    }
};
