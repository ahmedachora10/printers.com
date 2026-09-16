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
            'branchId' => $this->branch_id,
            'expenseCategoryId' => $this->expense_category_id,
            'categoryName' => $this->category?->name,
            'qty' => (float) $this->qty,
            'unitPrice' => (float) $this->unit_price,
            'total' => (float) $this->total,
            'paidFrom' => $this->paid_from->value,
            'supplierName' => $this->supplier_name,
            'receiptReference' => $this->receipt_reference,
            'comment' => $this->comment,
            // تاسك 112: المرفق عبر مسارٍ مفوَّض لا رابطٍ عام، والطلب المربوط.
            'attachmentName' => $this->attachment()?->file_name,
            'attachmentUrl' => $this->attachment() ? route('expenses.attachment', $this->id) : null,
            'serviceInvoiceId' => $this->service_invoice_id,
            'invoiceNumber' => $this->invoice?->invoice_number,
            'date' => $this->date->format('Y-m-d'),
            'dateLabel' => $this->date->format('d/m/Y'),
            'userName' => $this->user?->name,
            'createdAt' => $this->created_at?->format('d/m/Y H:i'),
        ];
    }
}
