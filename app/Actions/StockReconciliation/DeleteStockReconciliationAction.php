<?php

namespace App\Actions\StockReconciliation;

use App\Models\StockReconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Discards an in-progress count (lines cascade). Completed reconciliations are
 * permanent — their adjustments already live in the immutable stock ledger, so
 * deleting the count would orphan the audit trail.
 */
class DeleteStockReconciliationAction
{
    public function handle(StockReconciliation $reconciliation): void
    {
        if ($reconciliation->isCompleted()) {
            throw ValidationException::withMessages([
                'reconciliation' => 'لا يمكن حذف جرد مكتمل.',
            ]);
        }

        DB::transaction(fn () => $reconciliation->delete());
    }
}
