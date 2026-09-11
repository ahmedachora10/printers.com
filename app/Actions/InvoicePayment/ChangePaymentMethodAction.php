<?php

namespace App\Actions\InvoicePayment;

use App\Models\InvoicePayment;
use App\Models\PaymentMethod;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * تاسك 99 — تغيير طريقة دفع فاتورة أو دفعةٍ منها، ولو بعد الاعتماد.
 *
 * لا يمسّ مبلغاً ولا تاريخاً ولا حالة: الطريقة وحدها، وإيصالها إن طُلب. وكل
 * تغيير يُكتب في سجلّ النشاط على **الفاتورة** (لا على الدفعة) حتى تجمع شاشتها
 * تاريخ الطريقة كلّه في مكانٍ واحد.
 */
class ChangePaymentMethodAction
{
    public const LOG_DESCRIPTION = 'payment method changed';

    public function handle(
        ServiceInvoice|ProductInvoice|InvoicePayment $target,
        int $paymentMethodId,
        ?UploadedFile $receipt,
        User $by,
    ): void {
        DB::transaction(function () use ($target, $paymentMethodId, $receipt, $by) {
            $invoice = $target instanceof InvoicePayment ? $target->invoice : $target;
            $before = $target->payment_method_id;

            // ⚠️ الاستثناء الوحيد على قاعدة «invoice_payments للإضافة فقط»:
            // الطريقة وحدها تُصحَّح في مكانها. المبلغ والتاريخ لا يُمسّان أبداً —
            // تصحيحهما دفعةٌ سالبة كما كان. صفٌّ سالب ثم صفٌّ جديد هنا كان
            // سيضاعف أحداث التحصيل في تقرير المبيعات لمالٍ لم يتغيّر.
            $target->forceFill(['payment_method_id' => $paymentMethodId])->save();

            if ($receipt !== null) {
                $target->addMedia($receipt)->toMediaCollection($target::RECEIPT_COLLECTION);
            }

            if ($before !== $paymentMethodId) {
                activity('invoices')
                    ->causedBy($by)
                    ->performedOn($invoice)
                    ->withProperties([
                        'payment_id' => $target instanceof InvoicePayment ? $target->id : null,
                        'old' => $before ? PaymentMethod::withTrashed()->find($before)?->name : null,
                        'new' => PaymentMethod::withTrashed()->find($paymentMethodId)?->name,
                    ])
                    ->log(self::LOG_DESCRIPTION);
            }
        });
    }
}
