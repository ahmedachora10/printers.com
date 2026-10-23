<?php

namespace App\Http\Requests\Quotation;

use App\Http\Requests\ServiceInvoice\StoreServiceInvoiceRequest;
use Illuminate\Support\Arr;

/** عرض سعر خدمات: نموذج فاتورة الخدمة نفسه بلا دفعٍ ولا حالةٍ ولا نقاط. */
class StoreServiceQuotationRequest extends StoreServiceInvoiceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return Arr::except(parent::rules(), StoreProductQuotationRequest::NOT_QUOTED);
    }
}
