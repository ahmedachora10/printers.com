<?php

namespace App\Http\Requests\PurchaseOrder;

use App\Models\PurchaseOrder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'receipts' => ['required', 'array', 'min:1'],
            'receipts.*.line_id' => ['required', 'integer', 'exists:purchase_order_lines,id'],
            'receipts.*.qty' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var PurchaseOrder $po */
            $po = $this->route('purchase_order');
            $lines = $po->lines()->get()->keyBy('id');

            $hasReceipt = false;

            foreach ((array) $this->input('receipts', []) as $index => $receipt) {
                $qty = (int) ($receipt['qty'] ?? 0);

                if ($qty > 0) {
                    $hasReceipt = true;
                }

                $line = $lines->get((int) ($receipt['line_id'] ?? 0));

                if ($line === null) {
                    $validator->errors()->add("receipts.{$index}.line_id", 'بند غير صالح لهذا الأمر.');

                    continue;
                }

                if ($line->received_qty + $qty > $line->ordered_qty) {
                    $validator->errors()->add("receipts.{$index}.qty", 'الكمية المستلمة تتجاوز الكمية المطلوبة.');
                }
            }

            if (! $hasReceipt) {
                $validator->errors()->add('receipts', 'يجب إدخال كمية مستلمة واحدة على الأقل.');
            }
        });
    }
}
