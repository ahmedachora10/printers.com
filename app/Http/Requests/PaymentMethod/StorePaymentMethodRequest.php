<?php

namespace App\Http\Requests\PaymentMethod;

use App\Http\Requests\Concerns\ValidatesBranchScope;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;

/**
 * إنشاء طريقة الدفع وتعديلها (تاسك 59/242): مدير الفرع لفرعه وحده؛ السوبر أدمن فرعٌ أو
 * عدّة فروع أو عامة. الحصر يُرفض إن استُعملت الطريقة في فرعٍ خارج النطاق الجديد.
 */
class StorePaymentMethodRequest extends FormRequest
{
    use ValidatesBranchScope;

    protected string $scopedModel = PaymentMethod::class;

    public function authorize(): bool
    {
        return true;
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

    /** @return array<int, callable> */
    public function after(): array
    {
        return $this->narrowingCheck('فواتير أو دفعات');
    }
}
