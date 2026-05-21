<?php

namespace App\Http\Requests\PaymentMethod;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId        = auth()->user()->branchId;
        $paymentMethodId = $this->route('paymentMethod')?->id;

        return [
            'name'      => ['required', 'string', 'max:255', Rule::unique('payment_methods', 'name')->where('branch_id', $branchId)->whereNull('deleted_at')->ignore($paymentMethodId)],
            'is_active' => ['boolean'],
        ];
    }
}
