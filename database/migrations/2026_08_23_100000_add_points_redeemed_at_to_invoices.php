<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نقاط الولاء لا تُخصم من رصيد العميل إلا عند اعتماد الفاتورة. هذا العمود هو
 * لحظة الخصم الفعلي: ما دام فارغاً فالنقاط المسجَّلة على الفاتورة **محجوزة** لا
 * مخصومة — تُطرح من الرصيد المتاح لأي فاتورة أخرى، ولا تُمسّ في جدول العملاء.
 *
 * الفواتير القائمة قبل هذا التغيير كانت تخصم النقاط لحظة الإنشاء، فتُختم كلها
 * بتاريخ إنشائها: أرصدتها مخصومة فعلاً، والقاعدة الجديدة تسري على ما بعدها.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['service_invoices', 'product_invoices'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('points_redeemed_at')->nullable()->after('points_discount');
            });

            DB::table($table)
                ->where('points_redeemed', '>', 0)
                ->update(['points_redeemed_at' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        foreach (['service_invoices', 'product_invoices'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('points_redeemed_at');
            });
        }
    }
};
