<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تاسك 110 — المصروفات القائمة تبقى «نقد من الكاشير»: هو ما افترضه التقرير
     * منذ التاسك 97، فلا يتغيّر رقم يومٍ مضى.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('paid_from', 20)->default('cash_drawer')->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('paid_from');
        });
    }
};
