<?php

namespace App\Http\Resources\Expense;

use App\Enums\ExpenseSourceEnum;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ServiceInvoice;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $history = $this->isApproved() && $this->relationLoaded('activities')
            ? $this->activities
                ->where('event', 'updated')
                ->filter(fn ($activity) => $activity->created_at->gte($this->approved_at))
                ->sortByDesc('id')
                ->values()
            : collect();

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
            'canUnapprove' => $request->user()->can('unapprove', $this->resource),
            'canUpdate' => $request->user()->can('update', $this->resource),
            'canDelete' => $request->user()->can('delete', $this->resource),
            // تعديلات ما بعد الاعتماد: القديم ⇒ الجديد للحقول المتغيّرة، من ومتى.
            'history' => $history->isEmpty() ? [] : $this->readableHistory($history),
            'createdAt' => $this->created_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * سجلّ ما بعد الاعتماد بقيمٍ مقروءة: اسم الفئة ورقم الفاتورة بدل معرّفيهما،
     * ومصدر الدفع والتاريخ بصيغتهما المعروضة.
     *
     * ponytail: استعلامان لكل صفٍّ له سجلّ — نادرٌ (معتمدٌ ثم عُدّل) وفي صفحة من 15؛
     * يُجمع على مستوى الصفحة إن صار شائعاً.
     *
     * @return list<array<string, mixed>>
     */
    private function readableHistory(Collection $history): array
    {
        $values = $history->flatMap(fn ($a) => [$a->properties['old'] ?? [], $a->properties['attributes'] ?? []]);
        $categories = ExpenseCategory::whereKey($values->pluck('expense_category_id')->filter()->unique())->pluck('name', 'id');
        $invoices = ServiceInvoice::whereKey($values->pluck('service_invoice_id')->filter()->unique())->pluck('invoice_number', 'id');

        $readable = fn (array $fields) => collect($fields)->map(fn ($value, $field) => match (true) {
            $value === null => null,
            $field === 'expense_category_id' => $categories[$value] ?? $value,
            $field === 'service_invoice_id' => $invoices[$value] ?? $value,
            $field === 'paid_from' => ExpenseSourceEnum::tryFrom($value)?->label() ?? $value,
            $field === 'date' => Carbon::parse($value)->format('d/m/Y'),
            default => $value,
        })->all();

        return $history->map(fn ($activity) => [
            'id' => $activity->id,
            'byName' => $activity->causer?->name,
            'at' => $activity->created_at->format('d/m/Y H:i'),
            'old' => $readable($activity->properties['old'] ?? []),
            'new' => $readable($activity->properties['attributes'] ?? []),
        ])->all();
    }
}
