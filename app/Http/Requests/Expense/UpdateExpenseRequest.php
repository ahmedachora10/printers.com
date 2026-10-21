<?php

namespace App\Http\Requests\Expense;

use App\Enums\ExpenseSourceEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
{
    use ExpenseLinkRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $expense = $this->route('expense');
        $branchId = $expense->branch_id;

        return [
            // تاسك 198: سائق التسوية يتغيّر ولا يُفرَّغ (فيبقى المصروف تسويةً)، ولا يُضاف لمصروفٍ عادي.
            'delivery_provider_id' => $expense->delivery_provider_id === null ? ['prohibited'] : ['required', 'integer',
                Rule::exists('delivery_providers', 'id')->where('branch_id', $branchId)
                    ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $expense->delivery_provider_id))],
            // تاسك 102: فئةٌ عامة أو فئة فرع المصروف.
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))],
            'qty' => ['required', 'numeric', 'min:0.01'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            // تاسك 110: إلزاميّ — النقدي وحده يُطرح من «المتبقي من النقد».
            'paid_from' => ['required', Rule::enum(ExpenseSourceEnum::class)],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'receipt_reference' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:1000'],
            ...$this->linkRules($branchId),
            // تاسك 187: مرفقاتٌ قائمة يحذفها المستخدم من النافذة.
            'remove_attachment_ids' => ['nullable', 'array'],
            'remove_attachment_ids.*' => ['integer'],
            // تاسك 135: المحاسب لا ينقل مصروفاً إلى تاريخٍ قديم — تاريخه الحالي يبقى مقبولاً.
            'date' => ['required', 'date', Rule::when(
                ! StoreExpenseRequest::canBackdate() && $this->input('date') !== $this->route('expense')->date?->toDateString(),
                ['after_or_equal:today'],
            )],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['date.after_or_equal' => 'تسجيل مصروف بتاريخ قديم متاح لمدير الفرع ومدير النظام فقط.'];
    }
}
