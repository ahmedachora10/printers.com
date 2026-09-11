<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 102 — «فئات المصروفات تكون لكل فرع بشكل مستقل».
 *
 * على قاعدة طرق الدفع في التاسك 59: NULL = فئة عامة يراها كل فرع، وقيمة = فئة
 * يملكها فرعها وحده. الفئات القائمة تبقى NULL فلا يتغيّر أي مصروف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->timestamps();
            // القيد لا يرى الصفّ العام NULL؛ منع تكرار الاسم العام داخل فرعٍ يتولّاه الـForm Request.
            $table->unique(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'name']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['branch_id', 'created_at', 'updated_at']);
        });
    }
};
