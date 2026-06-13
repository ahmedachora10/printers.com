<?php

namespace App\Actions\Expense;

use App\Models\Expense;
use Illuminate\Support\Facades\DB;

class CreateExpenseAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): Expense
    {
        // branch_id is resolved in StoreExpenseRequest (own branch for
        // non super-admins, chosen branch for super-admin).
        $data['user_id'] = auth()->id();
        $data['total'] = bcmul((string) $data['qty'], (string) $data['unit_price'], 2);

        return DB::transaction(fn () => Expense::create($data));
    }
}
