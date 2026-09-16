<?php

namespace App\Http\Requests\Shipping;

use App\Enums\ExpenseSourceEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettleDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branchId = $this->route('invoice')->branch_id;

        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_from' => ['required', Rule::enum(ExpenseSourceEnum::class)],
            // فئةٌ عامة أو فئة فرع الطلب (تاسك 102).
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))],
        ];
    }
}
