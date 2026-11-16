<?php

namespace App\Http\Requests\PaymentMethod;

use App\Http\Requests\Concerns\ValidatesBranchScope;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * تاسك 59/242: مدير الفرع يكتب لفرعه وحده؛ السوبر أدمن يختار فرعاً أو عدّة فروع،
 * أو لا شيء فتكون الطريقة عامة. الاسم فريد بين ما يراه أيّ فرعٍ من النطاق.
 */
class StorePaymentMethodRequest extends FormRequest
{
    use ValidatesBranchScope;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareBranchScope();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->branchScopeRules(),
            'name' => ['required', 'string', 'max:255', $this->uniqueNameInScope('يوجد طريقة دفع بهذا الاسم متاحة لفرعك بالفعل.')],
            'is_active' => ['boolean'],
            'requires_attachment' => ['boolean'],
            'is_cash' => ['boolean'],
            'is_network' => ['boolean'],
        ];
    }

    protected function scopedRecord(): ?Model
    {
        return $this->route('paymentMethod');
    }

    protected function scopedModel(): string
    {
        return PaymentMethod::class;
    }
}
