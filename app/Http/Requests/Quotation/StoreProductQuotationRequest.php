<?php

namespace App\Http\Requests\Quotation;

use App\Http\Requests\ProductInvoice\StoreProductInvoiceRequest;
use Illuminate\Support\Arr;

/** عرض سعر منتجات: نموذج فاتورة المنتجات نفسه بلا دفعٍ ولا حالةٍ ولا نقاط. */
class StoreProductQuotationRequest extends StoreProductInvoiceRequest
{
    /** ما يخصّ الفاتورة وحدها — يُستوفى عند التحويل. */
    public const NOT_QUOTED = ['payment_method_id', 'receipt', 'status', 'print', 'quick', 'redeem_points', 'internal_notes', 'quotation_id'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return Arr::except(parent::rules(), self::NOT_QUOTED);
    }
}
