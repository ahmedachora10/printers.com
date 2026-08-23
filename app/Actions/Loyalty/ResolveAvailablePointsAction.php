<?php

namespace App\Actions\Loyalty;

use App\Enums\InvoiceStatusEnum;
use App\Models\Customer;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;

/**
 * الرصيد الذي يستطيع العميل استبداله فعلاً = رصيده المسجَّل ناقصاً ما حُجز منه.
 *
 * النقاط تُخصم من الرصيد عند اعتماد الفاتورة لا عند إنشائها، فالفاتورة غير
 * المعتمدة (آجلة أو مدفوعة جزئياً) تحمل نقاطاً **محجوزة**: قيمتها مخصومة من
 * إجمالي الفاتورة بالفعل، لكن رصيد العميل لم يُمسّ بعد. حجزُها هو ما يمنع
 * استبدال النقاط نفسها مرتين على فاتورتين تنتظران الاعتماد.
 *
 * الحجز يُشتقّ من الفواتير نفسها فلا يوجد عدّاد ثانٍ يمكن أن يفارق الحقيقة:
 * كل فاتورة غير معتمدة لها points_redeemed ولم تُختم بـ points_redeemed_at.
 * والفواتير القديمة — التي خُصمت نقاطها لحظة الإنشاء — مختومة كلها، فلا تُحجز
 * مرة أخرى فوق خصمٍ وقع.
 */
class ResolveAvailablePointsAction
{
    /**
     * الرصيد المتاح للاستبدال، مع استثناء حجز فاتورةٍ بعينها — الفاتورة قيد
     * التعديل تُحسب نقاطها المحجوزة لها هي، فإعادة إرسال العدد نفسه لا تُرفض.
     */
    public function handle(Customer $customer, ProductInvoice|ServiceInvoice|null $excluding = null): int
    {
        return max(0, (int) $customer->points_balance - $this->reserved((int) $customer->id, $excluding));
    }

    /** مجموع النقاط المحجوزة على فواتير العميل غير المعتمدة، من النوعين معاً. */
    public function reserved(int $customerId, ProductInvoice|ServiceInvoice|null $excluding = null): int
    {
        return $this->reservedFor([$customerId], $excluding)[$customerId] ?? 0;
    }

    /**
     * الحجز لعدة عملاء دفعةً واحدة — استعلامان لا استعلامان لكل عميل، تحتاجهما
     * قائمةُ البحث في نقطة البيع.
     *
     * @param  list<int>  $customerIds
     * @return array<int, int>
     */
    public function reservedFor(array $customerIds, ProductInvoice|ServiceInvoice|null $excluding = null): array
    {
        $customerIds = array_values(array_unique(array_filter($customerIds)));

        if ($customerIds === []) {
            return [];
        }

        $totals = [];

        foreach ([ServiceInvoice::class, ProductInvoice::class] as $model) {
            /** @var ProductInvoice|ServiceInvoice $instance */
            $instance = new $model;

            $rows = DB::table($instance->getTable())
                ->selectRaw('customer_id, SUM(points_redeemed) as total')
                ->whereIn('customer_id', $customerIds)
                ->whereNull('deleted_at')
                ->whereNull('points_redeemed_at')
                ->where('points_redeemed', '>', 0)
                ->whereIn('status', [InvoiceStatusEnum::DUE->value, InvoiceStatusEnum::PARTIALLY_PAID->value])
                ->when(
                    $excluding !== null && $excluding->exists && $excluding::class === $model,
                    fn ($q) => $q->where('id', '!=', $excluding->getKey()),
                )
                ->groupBy('customer_id')
                ->pluck('total', 'customer_id');

            foreach ($rows as $customerId => $total) {
                $totals[(int) $customerId] = ($totals[(int) $customerId] ?? 0) + (int) $total;
            }
        }

        return $totals;
    }
}
