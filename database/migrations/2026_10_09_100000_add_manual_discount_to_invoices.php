<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * خصمٌ يضيفه المحاسب أو المدير على فاتورة لم يكتمل سدادها — عادةً بعد عربون،
 * حين يُتّفق مع العميل على أقل من المسجَّل. يُطرح بعد النقاط وقبل الشحن.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['service_invoices', 'product_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('manual_discount', 12, 2)->default(0)->after('points_discount');
            });
        }
    }

    public function down(): void
    {
        foreach (['service_invoices', 'product_invoices'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('manual_discount'));
        }
    }
};
