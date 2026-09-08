<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 95 — ملاحظةٌ داخلية للموظفين والإدارة: تعليمات تنفيذ أو تنبيه للمحاسب،
 * لا يراها العميل ولا تُطبع في أي ورقة.
 *
 * حقلٌ **ثالث** لا تعديلٌ على القائمَين: `notes` على الفاتورة «ملاحظات للعميل»
 * وتُطبع تحت البنود منذ تاسك 26، و`service_invoice_lines.notes` تفصيلُ خدمةٍ
 * يُطبع تحت اسمها منذ تاسك 5. تغيير معنى أيٍّ منهما يقلب فواتير منشورة كثيرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->text('internal_notes')->nullable()->after('notes');
        });

        Schema::table('product_invoices', function (Blueprint $table) {
            $table->text('internal_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->dropColumn('internal_notes');
        });

        Schema::table('product_invoices', function (Blueprint $table) {
            $table->dropColumn('internal_notes');
        });
    }
};
