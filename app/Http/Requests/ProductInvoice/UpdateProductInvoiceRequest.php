<?php

namespace App\Http\Requests\ProductInvoice;

use App\Models\Branch;
use Illuminate\Support\Arr;

/**
 * تعديل فاتورة منتجات: قواعد الإنشاء نفسها، إلا أن الحالة لا تُعدَّل، وطرق الدفع
 * تُقرأ من فرع **الفاتورة** لا من فرع المعدِّل (مدير النظام بلا فرع)، والإيصال
 * لا يُطلب مرة ثانية إن كان مرفقاً سلفاً.
 */
class UpdateProductInvoiceRequest extends StoreProductInvoiceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['status']);
    }

    /** @return array<int, int> */
    protected function enabledPaymentMethodIds(): array
    {
        $invoice = $this->route('invoice');
        $branch = Branch::find($invoice?->branch_id);
        $ids = $branch ? $branch->enabledPaymentMethods()->pluck('id')->all() : [];

        if ($invoice?->payment_method_id !== null) {
            $ids[] = (int) $invoice->payment_method_id;
        }

        return array_values(array_unique($ids));
    }

    protected function paymentMethodRequiresAttachment(): bool
    {
        return parent::paymentMethodRequiresAttachment() && ! $this->route('invoice')?->hasReceipt();
    }
}
