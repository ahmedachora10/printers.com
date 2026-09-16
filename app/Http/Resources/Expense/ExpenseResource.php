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
            'attachmentUrl' => $this->attachment() ? route('expenses.attachment', $this->id) : null,
            'serviceInvoiceId' => $this->service_invoice_id,
            'invoiceNumber' => $this->invoice?->invoice_number,
            'date' => $this->date->format('Y-m-d'),
            'dateLabel' => $this->date->format('d/m/Y'),
            'userName' => $this->user?->name,
            // تاسك 113: الاعتماد وما تسمح به السياسة لهذا المستخدم على هذا الصفّ.
            'approvedAt' => $this->approved_at?->format('d/m/Y H:i'),
            'approvedByName' => $this->approvedBy?->name,
            'canApprove' => $request->user()->can('approve', $this->resource),
            'canUpdate' => $request->user()->can('update', $this->resource),
            'canDelete' => $request->user()->can('delete', $this->resource),
            // تعديلات ما بعد الاعتماد: القديم ⇒ الجديد للحقول المتغيّرة، من ومتى.
            'history' => $this->isApproved() && $this->relationLoaded('activities')
                ? $this->activities
                    ->where('event', 'updated')
                    ->filter(fn ($activity) => $activity->created_at->gte($this->approved_at))
                    ->sortByDesc('id')
                    ->map(fn ($activity) => [
                        'id' => $activity->id,
                        'byName' => $activity->causer?->name,
                        'at' => $activity->created_at->format('d/m/Y H:i'),
                        'old' => $activity->properties['old'] ?? [],
                        'new' => $activity->properties['attributes'] ?? [],
                    ])->values()->all()
                : [],
            'createdAt' => $this->created_at?->format('d/m/Y H:i'),
        ];
    }
}
