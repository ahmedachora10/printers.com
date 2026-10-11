<?php

namespace App\Actions\Shipping;

use App\Actions\Incentive\RecalculateIncentivePlanAction;
use App\Enums\InvoiceStatusEnum;
use App\Models\InvoicePayment;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceShipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * المحاسب يعتمد توصيلاً طُلب بعد اعتماد الفاتورة: يُضاف رسمه إلى الفاتورة متبقّياً
 * عليها، ويُحصَّل بعدها من «تسجيل دفعة» — فوراً في الفرع أو حين يعود به السائق.
 *
 * الرسم يدخل الإجمالي والضريبة وحدهما، بنسبة ضريبة الفاتورة: هو خارج كل أساس
 * عمولة ونقطة وريبيت (تاسك 93)، فلا يُعاد حساب شيءٍ مما كتبه الاعتماد.
 *
 * والمدفوعة تبقى «مدفوعة»: إرجاعها «مدفوعة جزئياً» يجعل دفعتها الأخيرة تعيد
 * اعتمادها فتُكتب العمولة مرتين. متبقّيها يُقرأ من صفوف الدفعات (owesAfterApproval).
 */
class ApproveShipmentAction
{
    public function __construct(private readonly RecalculateIncentivePlanAction $recalculateIncentive) {}

    public function handle(ServiceInvoiceShipment $shipment, User $actor): void
    {
        DB::transaction(function () use ($shipment, $actor) {
            $invoice = ServiceInvoice::query()->whereKey($shipment->service_invoice_id)->lockForUpdate()->firstOrFail();
            $shipment->refresh();

            if (! $shipment->isPending()) {
                throw ValidationException::withMessages(['shipment' => 'طلب التوصيل هذا اعتُمد مسبقاً.']);
            }

            if (! in_array($invoice->status, [InvoiceStatusEnum::PAID, InvoiceStatusEnum::PARTIALLY_PAID], true)) {
                throw ValidationException::withMessages(['shipment' => 'لا يُضاف توصيلٌ إلا لفاتورة معتمدة.']);
            }

            $fee = round((float) $shipment->fee, 2);

            if ($fee > 0) {
                $this->recordWhatWasCollected($invoice, $actor);

                $total = round((float) $invoice->total_amount + $fee, 2);
                $net = round($total / (1 + (float) $invoice->vat_pct / 100), 2);

                $invoice->update([
                    'shipping_fee' => round((float) $invoice->shipping_fee + $fee, 2),
                    'total_amount' => $total,
                    'vat_amount' => round($total - $net, 2),
                ]);

                // المحقَّق يقرأ total_amount، والتوصيل داخلٌ فيه منذ نقطة البيع.
                $this->recalculateIncentive->refreshForInvoice($invoice);
            }

            $shipment->forceFill(['approved_by' => $actor->id, 'approved_at' => now()])->save();
        });
    }

    /**
     * المسدَّدة عند البيع بلا صفوف دفعات محصَّلُها إجماليُّها ضمناً (paidAmount)،
     * فلو ارتفع الإجمالي لصار الرسم محصَّلاً. يُكتب ما حُصِّل صفّاً بيوم سدادها
     * وطريقته وإيصاله — فلا يتحرّك ريالٌ في أي تقرير — ثم يبقى الرسم متبقّياً.
     */
    private function recordWhatWasCollected(ServiceInvoice $invoice, User $actor): void
    {
        if ($invoice->status !== InvoiceStatusEnum::PAID || $invoice->payments()->exists()) {
            return;
        }

        $payment = $invoice->payments()->create([
            'branch_id' => $invoice->branch_id,
            'payment_method_id' => $invoice->payment_method_id,
            'amount' => round((float) $invoice->total_amount, 2),
            'paid_at' => $invoice->paid_at,
            'recorded_by' => $actor->id,
            'notes' => 'المحصَّل عند اعتماد الفاتورة',
        ]);

        $invoice->getFirstMedia(ServiceInvoice::RECEIPT_COLLECTION)?->copy($payment, InvoicePayment::RECEIPT_COLLECTION);
    }
}
