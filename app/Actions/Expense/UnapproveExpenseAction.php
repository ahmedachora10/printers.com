<?php

namespace App\Actions\Expense;

use App\Models\Expense;
use Illuminate\Support\Facades\DB;

/** إلغاء اعتماد مصروف: يعود إلى المحاسب ويخرج من التقارير حتى يُعتمد من جديد. */
class UnapproveExpenseAction
{
    public function handle(Expense $expense): void
    {
        DB::transaction(function () use ($expense) {
            Expense::whereKey($expense->id)->toBase()->update(['approved_at' => null, 'approved_by' => null, 'updated_at' => now()]);

            activity('expenses')
                ->performedOn($expense)
                ->causedBy(auth()->user())
                ->log('إلغاء اعتماد المصروف');
        });
    }
}
