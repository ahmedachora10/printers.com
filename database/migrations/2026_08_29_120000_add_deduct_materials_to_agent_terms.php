<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 69: خيار «خصم تكلفة الخامات من عمولة صاحب العمولة».
 *
 * عمولة الموظف تُحسب منذ التاسك 7 على (صافي السطر − الخامات)، بينما عمولة صاحب
 * العمولة تُحسب على صافي السطر كاملاً. العميل يريد المساواة بينهما **في بعض
 * الحالات** لا في كلّها، فالخيار يُكتب على شروط كل فرع — `agent_branch` هو مصدر
 * الحقيقة منذ التاسك 20-د — ويُكتب مثيلٌ له في `agent_profiles` كما فعل `rate`
 * تماماً، فهو ما يُملأ به الصفّ عند ربط فرعٍ جديد.
 *
 * الافتراض `false` = سلوك اليوم بلا تغيير لأي مندوب قائم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_branch', function (Blueprint $table) {
            $table->boolean('deduct_materials')->default(false)->after('rate');
        });

        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->boolean('deduct_materials')->default(false)->after('rate');
        });
    }

    public function down(): void
    {
        Schema::table('agent_branch', function (Blueprint $table) {
            $table->dropColumn('deduct_materials');
        });

        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropColumn('deduct_materials');
        });
    }
};
