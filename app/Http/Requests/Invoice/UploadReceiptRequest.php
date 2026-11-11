<?php

namespace App\Http\Requests\Invoice;

use App\Models\ServiceInvoice;
use Illuminate\Foundation\Http\FormRequest;

class UploadReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ServiceInvoice::receiptRules(true);
    }

    public function messages(): array
    {
        return ['receipts.required' => 'يجب اختيار ملف الإيصال.'];
    }
}
