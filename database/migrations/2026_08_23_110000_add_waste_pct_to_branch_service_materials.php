<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نسبة الهالك لكل خامة: الطباعة لا تخلو من فاقد قصٍّ وضبطِ ألوان، فالمستهلك فعلاً
 * من المخزون أكبر من الكمية المحسوبة على المقاس. صفرٌ افتراضاً فلا يتغيّر خصمُ أي
 * خامة معرَّفة اليوم حتى تُضبط نسبتها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_service_materials', function (Blueprint $table) {
            $table->decimal('waste_pct', 5, 2)->default(0)->after('qty_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('branch_service_materials', function (Blueprint $table) {
            $table->dropColumn('waste_pct');
        });
    }
};
