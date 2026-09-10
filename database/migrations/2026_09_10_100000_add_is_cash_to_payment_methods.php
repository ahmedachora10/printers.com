<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 97 — علامة «طريقة نقدية»: تقرير المبيعات يطرح المصروفات من النقد وحده،
 * ولا شيء غير الاسم كان يدلّ على النقد — والاسم يعدّله العميل («نقد ( كاش)»).
 * القائم يُعلَّم بما يبدأ اسمه بـ«نقد»، وما بعده يُضبط من شاشة طرق الدفع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('is_cash')->default(false)->after('requires_attachment');
        });

        DB::table('payment_methods')->where('name', 'like', 'نقد%')->update(['is_cash' => true]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('is_cash');
        });
    }
};
