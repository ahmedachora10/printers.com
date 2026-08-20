<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سقف سعر البيع للخدمة: لا يبيع الموظف بأعلى منه. الخانة اختيارية — NULL يعني
 * أن السعر مفتوح كما كان قبل هذه الهجرة، فلا تتغيّر أي خدمة قائمة.
 *
 * معنى الرقم يتبع نوع تسعير الخدمة: خدمة «بالوحدة» يُقارَن سقفها بسعر الوحدة،
 * وخدمة «بالمتر المربع» يُقارَن سقفها بسعر المتر الفعلي للسطر (السعر ÷ المساحة)
 * — إذ إن سعر القطعة هناك يتبع المقاس فلا معنى لسقفٍ ثابت عليه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->decimal('max_selling_price', 12, 2)->nullable()->after('max_discount_pct');
        });
    }

    public function down(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropColumn('max_selling_price');
        });
    }
};
