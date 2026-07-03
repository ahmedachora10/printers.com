<?php

namespace App\Http\Requests\ServiceInvoice;

use App\Models\ServiceInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var ServiceInvoice $invoice */
        $invoice = $this->route('invoice');
        $customer = $invoice->customer;

        $phone = ['required', 'string', 'max:20'];

        // Editing a linked customer must not collide with another record's phone.
        // When the invoice has no customer yet, the phone is resolved via
        // found-or-create (an existing phone links that customer), so no unique
        // rule — otherwise a known phone would be rejected instead of linked.
        if ($customer !== null) {
            $phone[] = Rule::unique('customers', 'phone')
                ->where('branch_id', $customer->branch_id)
                ->ignore($customer->id)
                ->whereNull('deleted_at');
        }

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => $phone,
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'اسم العميل مطلوب.',
            'phone.required' => 'رقم الجوال مطلوب.',
            'phone.unique' => 'رقم الجوال مستخدم لعميل آخر.',
        ];
    }
}
