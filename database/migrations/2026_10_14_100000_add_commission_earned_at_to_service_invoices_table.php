<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 182: عمولة الفاتورة قد تُكتب قبل اكتمال سدادها (حين يغطّي المحصَّلُ الحدَّ
 * الأدنى). هذا الختم وحده يقول «كُتبت عمولتها» — وجودُ صفوف ledger لا يصلح علامةً،
 * فالتعديل يعكسها ويعيد كتابتها. وقيمته تاريخ استحقاقها (earned_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->timestamp('commission_earned_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->dropColumn('commission_earned_at');
        });
    }
};
