<?php

namespace App\Http\Requests\ServiceInvoice;

use Illuminate\Foundation\Http\FormRequest;

/** توصيلٌ بعد اعتماد الفاتورة — طلبٌ واحد بقواعد طلبات نقطة البيع نفسها (`shipments.0.*`). */
class RequestShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...StoreServiceInvoiceRequest::shipmentRules(),
            'shipments' => ['required', 'array', 'size:1'],
        ];
    }
}
