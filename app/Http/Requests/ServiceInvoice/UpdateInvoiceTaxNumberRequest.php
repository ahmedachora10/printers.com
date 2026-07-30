<?php

namespace App\Http\Requests\ServiceInvoice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The tax number an employee may add or correct on their DUE invoice's customer
 * from the POS edit screen. Deliberately narrower than UpdateInvoiceCustomerRequest:
 * name and phone stay with the accountant / branch-admin / super-admin, since
 * changing those rewrites a record shared by all of that customer's invoices.
 */
class UpdateInvoiceTaxNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Saudi VAT numbers are exactly 15 digits; blank clears the field.
            'tax_number' => ['nullable', 'digits:15'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tax_number.digits' => 'الرقم الضريبي يجب أن يتكون من 15 رقماً.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $taxNumber = trim((string) $this->input('tax_number', ''));

        $this->merge(['tax_number' => $taxNumber === '' ? null : $taxNumber]);
    }
}
