<?php

namespace App\Http\Requests\PurchaseRequest;

use Illuminate\Foundation\Http\FormRequest;

class RejectPurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A rejection is always explained to the requester.
            'decision_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'decision_reason' => 'سبب الرفض',
        ];
    }
}
