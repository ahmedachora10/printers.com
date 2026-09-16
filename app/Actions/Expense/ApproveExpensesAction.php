<?php

namespace App\Actions\Expense;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * تاسك 113 — يعتمد ما لم يُعتمد من الاستعلام المُمرَّر: مصروفاً واحداً أو كل ما
 * يطابق فلاتر الشاشة. تحديثٌ واحد بلا أحداث نموذج، فلا يُكتب سطر سجلٍّ لكل
 * مصروف، بل سطرٌ واحد للعملية.
 */
class ApproveExpensesAction
{
    /** @param Builder<Expense> $query */
    public function handle(Builder $query): int
    {
        return DB::transaction(function () use ($query) {
            $pending = (clone $query)->whereNull('approved_at');
            $count = (clone $pending)->count();
            $total = (float) (clone $pending)->sum('total');

            if ($count === 0) {
                return 0;
            }

            $pending->toBase()->update(['approved_at' => now(), 'approved_by' => auth()->id(), 'updated_at' => now()]);

            activity('expenses')
                ->causedBy(auth()->user())
                ->withProperties(['count' => $count, 'total' => $total])
                ->log("اعتماد {$count} مصروف بمجموع ".number_format($total, 2).' ر.س');

            return $count;
        });
    }
}
