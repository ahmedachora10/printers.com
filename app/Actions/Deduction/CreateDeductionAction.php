<?php

namespace App\Actions\Deduction;

use App\Models\EmployeeDeduction;
use Illuminate\Support\Facades\DB;

class CreateDeductionAction
{
    /** @param  array<string, mixed>  $data */
    public function handle(array $data): EmployeeDeduction
    {
        return DB::transaction(fn () => EmployeeDeduction::create([
            ...$data,
            'deducted_by' => auth()->id(),
            'deducted_at' => now(),
        ]));
    }
}
