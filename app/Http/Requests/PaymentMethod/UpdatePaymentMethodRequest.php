<?php

namespace App\Http\Requests\PaymentMethod;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var PaymentMethod|null $paymentMethod */
        $paymentMethod = $this->route('paymentMethod');

        // النطاق لا يُنقل بالتعديل: صفّ الفرع يبقى لفرعه والعامّ يبقى عاماً،
        // فالتفرّد يُقاس على نطاق الصفّ نفسه (تاسك 59).
        $branchId = $paymentMethod?->branch_id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('payment_methods', 'name')
                    ->whereNull('deleted_at')
                    ->ignore($paymentMethod?->id)
                    ->where(fn ($q) => $branchId === null
                        ? $q->whereNull('branch_id')
                        : $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId))),
            ],
            'is_active' => ['boolean'],
            'requires_attachment' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'يوجد طريقة دفع بهذا الاسم متاحة لفرعك بالفعل.',
        ];
    }
}
