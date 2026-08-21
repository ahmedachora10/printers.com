<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ما الذي يقيسه unit_price في هذا السطر؟ صار سعرُ سطرِ الخدمة المسعّرة بالمتر
 * المربع «سعر المتر» لا «سعر القطعة»، والإجمالي = الكمية × المساحة × السعر.
 * الأسطر المحفوظة قبل هذا التغيير تحمل سعر القطعة، ولا تُعاد كتابتها — فالعمود
 * هو ما يميّزها: 'sqm' سعر متر، 'unit' سعر وحدة، و null سطرٌ قديم يُقرأ على
 * المعنى السابق (سعر القطعة للخدمات المسعّرة بالمتر) فتبقى إجمالياته كما طُبعت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoice_lines', function (Blueprint $table) {
            $table->string('unit_price_basis', 10)->nullable()->after('height_cm');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('unit_price_basis');
        });
    }
};
