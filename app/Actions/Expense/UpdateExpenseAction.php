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
        $attachment = $data['attachment'] ?? null;
        $removeAttachment = (bool) ($data['remove_attachment'] ?? false);
        unset($data['attachment'], $data['remove_attachment']);

        return DB::transaction(function () use ($expense, $data, $attachment, $removeAttachment) {
            $expense->update($data);

            // singleFile(): a new upload replaces the old one on its own.
            if ($attachment) {
                $expense->addMedia($attachment)->toMediaCollection(Expense::ATTACHMENT);
            } elseif ($removeAttachment) {
                $expense->clearMediaCollection(Expense::ATTACHMENT);
            }

            return $expense->fresh();
        });
    }
}
