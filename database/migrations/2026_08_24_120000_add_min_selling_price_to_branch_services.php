<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أرضية سعر البيع للخدمة (تاسك 64): لا يبيع الموظف بأقل منها — مرآةُ
 * `max_selling_price` الذي أضافته هجرة 2026_08_20_120000. الخانة اختيارية،
 * وNULL يعني أن السعر مفتوح من الأسفل كما كان قبل هذه الهجرة، فلا تتغيّر أي
 * خدمة قائمة.
 *
 * معنى الرقم يتبع نوع تسعير الخدمة كما يتبعه السقف: خدمة «بالوحدة» يُقارَن
 * حدّها بسعر الوحدة، وخدمة «بالمتر المربع» يُقارَن بسعر المتر — وهو ما يكتبه
 * الموظف في السطر منذ صار سعرُ سطر المتر سعرَ مترٍ لا سعرَ قطعة.
 *
 * وهذه أرضية واحدة من اثنتين: الثانية تكلفةُ خامات السطر (تاسك 65)، والفعّالة
 * أعلاهما.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->decimal('min_selling_price', 12, 2)->nullable()->after('max_selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropColumn('min_selling_price');
        });
    }
};
