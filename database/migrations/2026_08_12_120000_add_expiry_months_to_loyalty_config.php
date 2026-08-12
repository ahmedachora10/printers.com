<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * انتهاء صلاحية النقاط بخمول العميل: عدد الأشهر التي إن مرّت بلا أي شراء صُفِّر
 * رصيد نقاطه. NULL = لا انتهاء صلاحية، وهو الوضع الذي كان عليه النظام، فلا
 * تتبدّل أرصدةُ أحدٍ لمجرّد ترقية النسخة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_config', function (Blueprint $table) {
            $table->unsignedSmallInteger('expiry_months')->nullable()->after('min_redemption_points');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_config', function (Blueprint $table) {
            $table->dropColumn('expiry_months');
        });
    }
};
