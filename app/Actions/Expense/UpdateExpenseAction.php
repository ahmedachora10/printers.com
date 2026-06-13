<?php

namespace App\Actions\Expense;

use App\Models\Expense;
use Illuminate\Support\Facades\DB;

class UpdateExpenseAction
{
    /** @param array<string, mixed> $data */
    public function handle(Expense $expense, array $data): Expense
    {
        $qty = $data['qty'] ?? $expense->qty;
        $unitPrice = $data['unit_price'] ?? $expense->unit_price;
        $data['total'] = bcmul((string) $qty, (string) $unitPrice, 2);

        return DB::transaction(function () use ($expense, $data) {
            $expense->update($data);

            return $expense->fresh();
        });
    }
}
