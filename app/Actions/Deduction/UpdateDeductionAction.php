<?php

namespace App\Actions\Deduction;

use App\Models\EmployeeDeduction;
use Illuminate\Support\Facades\DB;

/**
 * تاسك 126 — تصحيح قيدٍ مسجَّل. لا سجلّ يدويّ هنا: `LogsActivity` على النموذج
 * يكتب القديم والجديد للحقول المتغيّرة وحدها، وهو ما تعرضه نافذة التعديل.
 */
class UpdateDeductionAction
{
    /** @param  array<string, mixed>  $data */
    public function handle(EmployeeDeduction $deduction, array $data): EmployeeDeduction
    {
        return DB::transaction(function () use ($deduction, $data): EmployeeDeduction {
            $deduction->update($data);

            return $deduction->fresh();
        });
    }
}
