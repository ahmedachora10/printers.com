<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 68: اعتماد الطلب الداخلي صار يغذّي المخزون بنفسه.
 *
 * قبل هذا كان المخزون يصله شيء بخطوتين يدويّتين بعد الاعتماد: تحويلٌ لأمر شراء
 * (M29) ثم استلامه. وبقاء المسارين معاً يُدخل الكمية **مرّتين** في
 * `stock_movements` — وهو جدول إدراج فقط لا يُصحَّح صفٌّ فيه ولا يُحذف، فالخطأ
 * لا يُصلَح إلا بقيد تسوية. لذا يُختم الطلب المُغذّى بـ`stock_fed_at` ويُمنع
 * تحويله؛ والطلبات المعتمدة قبل هذه الهجرة تبقى بلا ختم فتُحوَّل كما كانت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->timestamp('stock_fed_at')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('stock_fed_at');
        });
    }
};
