<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نسبةُ حركة المخزون إلى سطر الخدمة الذي استهلكها. مرجع الحركة يبقى الفاتورة كما
 * هو — عليه يقوم الخصم والإرجاع — وهذا العمود إضافةٌ للتقرير وحده: فاتورةٌ فيها
 * خدمتان تشتركان في المنتج نفسه لا يمكن نسبُ استهلاكها لخدمةٍ بعينها بدونه.
 *
 * إضافةُ عمودٍ لا تمسّ صفاً قائماً، فقاعدةُ «جدول إدراج فقط» سليمة؛ وحركاتُ ما
 * قبل الهجرة تبقى فارغةَ العمود وتُعرض في التقرير غير منسوبةٍ لخدمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('service_invoice_line_id')
                ->nullable()
                ->after('reference_type')
                ->constrained('service_invoice_lines')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_invoice_line_id');
        });
    }
};
