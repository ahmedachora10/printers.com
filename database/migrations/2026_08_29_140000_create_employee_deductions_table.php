<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاسك 74: حسم/خصم تُطبّقه الإدارة على موظف — قصورٌ في الأداء، أو خطأ في التنفيذ،
 * أو عدم التزام بالمهام، أو حالة أخرى — بسببه وقيمته معاً.
 *
 * بندٌ مستقلّ لا يُعيد كتابة رقمٍ منشور: `commission_ledger` و`bonus_payments`
 * جدولان غير قابلين للتعديل، وحقن الحسم في أيّهما كان سيشوّه تقرير العمولات
 * والتسويات. فيُعرض الحسم بجانب المستحق ولا يمسّه.
 *
 * ⚠️ IMMUTABLE after insert — كنظيره `bonus_payments`: لا تعديل ولا حذف، والإلغاء
 * يكون بقيدٍ معاكس.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 50);
            // نصّ إلزامي حين يكون السبب «أخرى» — العميل طلب السبب والقيمة معاً.
            $table->string('reason_note', 255)->nullable();
            $table->foreignId('deducted_by')->constrained('users');
            $table->timestamp('deducted_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'deducted_at']);
            $table->index(['user_id', 'deducted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_deductions');
    }
};
