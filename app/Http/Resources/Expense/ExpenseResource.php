<?php

namespace App\Http\Resources\Expense;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expenseCategoryId' => $this->expense_category_id,
            'categoryName' => $this->category?->name,
            'qty' => (float) $this->qty,
            'unitPrice' => (float) $this->unit_price,
            'total' => (float) $this->total,
            'supplierName' => $this->supplier_name,
            'receiptReference' => $this->receipt_reference,
            'comment' => $this->comment,
            'date' => $this->date->format('Y-m-d'),
            'dateLabel' => $this->date->format('d/m/Y'),
            'userName' => $this->user?->name,
            'createdAt' => $this->created_at?->format('d/m/Y H:i'),
        ];
    }
}
