<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ملاحظات العميل: «إمكانية حذف الخصومات».
 *
 * الحسم ليس رقماً منشوراً كصفّ العمولة أو المكافأة المصروفة — لا يقرؤه أحدٌ خارج
 * شاشته، ولا تُبنى عليه فاتورة. فحين يُسجَّل خطأً، الإلغاء بقيدٍ معاكس يُضاعف
 * السطور في كشفٍ يقرؤه الموظف نفسه، ولا يفيد.
 *
 * فالحذف هنا `SoftDeletes`: يختفي الصفّ من كل عرضٍ ومجموع، ويبقى في القاعدة أثراً
 * للمراجعة. ولا تعديل بعدُ: الصفّ لا يُعاد كتابته، إنما يُلغى كاملاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_deductions', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('employee_deductions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
