<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 242 — «يكون لكل فرع طرق دفع خاصة به». يستبدل branch_id الواحد (تاسك 59)
 * بجدول وسيط: بلا صفوف = عامة. طرق الفروع تنتقل بفرعها، والعامة تبقى عامة حتى
 * يحصرها السوبر أدمن يدوياً (جواب العميل 16/11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_payment_method', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->primary(['payment_method_id', 'branch_id']);
        });

        DB::table('branch_payment_method')->insertUsing(
            ['payment_method_id', 'branch_id'],
            DB::table('payment_methods')->whereNotNull('branch_id')->select('id', 'branch_id'),
        );

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique(['name', 'branch_id']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // طريقةٌ لعدّة فروع تعود إلى أوّلها — لا يحمل العمود الواحد أكثر.
        foreach (DB::table('branch_payment_method')->orderBy('branch_id')->get() as $row) {
            DB::table('payment_methods')->where('id', $row->payment_method_id)->whereNull('branch_id')
                ->update(['branch_id' => $row->branch_id]);
        }

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->unique(['name', 'branch_id']);
        });

        Schema::dropIfExists('branch_payment_method');
    }
};
