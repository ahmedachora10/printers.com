<?php

namespace App\Http\Requests\PaymentMethod;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = auth()->user()->branchId;

        return [
            'name'      => ['required', 'string', 'max:255', Rule::unique('payment_methods', 'name')->where('branch_id', $branchId)->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ];
    }
}
