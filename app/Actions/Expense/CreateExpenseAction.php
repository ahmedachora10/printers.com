<?php

namespace App\Actions\Expense;

use App\Models\Expense;
use Illuminate\Support\Facades\DB;

class CreateExpenseAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): Expense
    {
        $user = auth()->user();
        $data['branch_id'] = $user->branchId;
        $data['user_id'] = $user->id;
        $data['total'] = bcmul((string) $data['qty'], (string) $data['unit_price'], 2);

        return DB::transaction(fn () => Expense::create($data));
    }
}
