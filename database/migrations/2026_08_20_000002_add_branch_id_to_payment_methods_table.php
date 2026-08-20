<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 59 — «إذا ممكن التحكم في إضافة وحذف طرق الدفع».
 *
 * طرق الدفع كانت عامة تماماً منذ هجرة 2026_05_21_130001 التي أسقطت `branch_id`،
 * فالإضافة والحذف كانا للسوبر أدمن وحده: لو مُنحا لمدير الفرع لحذف طريقةً
 * يستعملها فرع آخر. يعود العمود هنا **قابلاً للـNULL** على قاعدة التاسك 47 نفسها:
 * NULL = صفّ عام يراه كل فرع، وقيمة = صفّ يملكه فرعه وحده.
 *
 * كل الصفوف القائمة تبقى NULL فلا تتأثر بيانة ولا فاتورة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            // التفرّد ينتقل من الاسم وحده إلى (الاسم، الفرع) كما فعل التاسك 47
            // بأسعار الدليل: فرعان قد يسمّيان طريقتيهما «شبكة»، ولا يجوز للفرع
            // الواحد أن يكرّر اسماً. وتكرار الاسم العام في فرعٍ يمنعه الـForm
            // Request لا القيد — القيد لا يرى الصفّ العام NULL.
            $table->dropUnique(['name']);
            $table->unique(['name', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique(['name', 'branch_id']);
            $table->unique('name');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
