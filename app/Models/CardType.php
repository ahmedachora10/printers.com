<?php

namespace App\Models;

use App\Models\Concerns\ScopedToBranches;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * تاسك 146 — نوع بطاقة (مدى، فيزا، ماستر كارد…).
 * تاسك 243 — «يكون لكل فرع بطاقات خاصة به»: فرعٌ أو عدّة فروع، وبلا فروع = عام.
 */
class CardType extends Model
{
    use ScopedToBranches, SoftDeletes;

    protected $fillable = ['name'];

    /**
     * فروع المطابقات التي سجّلت مبالغ لهذا النوع — فلا يُحصر خارجها.
     *
     * @return list<int>
     */
    public function usedInBranchIds(): array
    {
        return DB::table('account_reconciliation_devices')
            ->join('account_reconciliations', 'account_reconciliations.id', '=', 'account_reconciliation_devices.account_reconciliation_id')
            ->where('account_reconciliation_devices.card_type_id', $this->id)
            ->distinct()
            ->pluck('account_reconciliations.branch_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
