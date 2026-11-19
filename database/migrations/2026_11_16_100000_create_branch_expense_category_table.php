<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 238 — فئة المصروف لعدّة فروع. يستبدل branch_id الواحد (تاسك 102) بجدول وسيط:
 * بلا صفوف = عامة. كل فئة محصورة تنتقل بفرعها، والعامة تبقى عامة حتى يحصرها السوبر أدمن.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_expense_category', function (Blueprint $table) {
            $table->foreignId('expense_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->primary(['expense_category_id', 'branch_id']);
        });

        DB::table('branch_expense_category')->insertUsing(
            ['expense_category_id', 'branch_id'],
            DB::table('expense_categories')->whereNotNull('branch_id')->select('id', 'branch_id'),
        );

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'name']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // فئةٌ لعدّة فروع تعود إلى أوّلها — لا يحمل العمود الواحد أكثر.
        foreach (DB::table('branch_expense_category')->orderBy('branch_id')->get() as $row) {
            DB::table('expense_categories')->where('id', $row->expense_category_id)->whereNull('branch_id')
                ->update(['branch_id' => $row->branch_id]);
        }

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->unique(['branch_id', 'name']);
        });

        Schema::dropIfExists('branch_expense_category');
    }
};
