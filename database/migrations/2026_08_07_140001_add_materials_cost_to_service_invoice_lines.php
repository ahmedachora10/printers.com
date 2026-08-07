<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لقطة تكلفة الخامات وقت الفوترة. تُخصم من نصيب السطر من قيمة الفاتورة الصافية
 * قبل احتساب عمولة الموظف، فلا يأخذ عمولة على تكلفة تحمّلها المركز.
 *
 * materials_cost = للوحدة الواحدة، materials_total = × الكمية. يُخزَّن الإجمالي
 * غير مقصوص (تكلفة فعلية تُجمَّع في التقارير)؛ القصّ عند صفر يحدث في الحساب فقط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoice_lines', function (Blueprint $table) {
            $table->decimal('materials_cost', 12, 2)->default(0)->after('commission_amount');
            $table->decimal('materials_total', 12, 2)->default(0)->after('materials_cost');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['materials_cost', 'materials_total']);
        });
    }
};
