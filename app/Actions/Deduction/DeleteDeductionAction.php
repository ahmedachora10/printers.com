<?php

namespace App\Actions\Deduction;

use App\Models\EmployeeDeduction;
use Illuminate\Support\Facades\DB;

/**
 * ملاحظات العميل: «إمكانية حذف الخصومات» — حسمٌ سُجّل خطأً يُلغى بحذفه لا بقيدٍ
 * معاكس، كي لا يقرأ الموظف سطرين عن حسمٍ لم يقع.
 *
 * والحذف soft: يخرج الصفّ من كل عرضٍ ومجموع ويبقى في القاعدة، ويُسجَّل الفاعل في
 * سجلّ النشاط — فلا يضيع أثر مبلغٍ مسّ راتب موظف.
 */
class DeleteDeductionAction
{
    public function handle(EmployeeDeduction $deduction): void
    {
        DB::transaction(function () use ($deduction) {
            activity('incentives')
                ->performedOn($deduction)
                ->causedBy(auth()->user())
                ->withProperties([
                    'user_id' => $deduction->user_id,
                    'branch_id' => $deduction->branch_id,
                    'amount' => (float) $deduction->amount,
                    'reason' => $deduction->reasonLabel(),
                    'deducted_at' => $deduction->deducted_at?->toDateTimeString(),
                ])
                ->log('حذف حسم موظف');

            $deduction->delete();
        });
    }
}
