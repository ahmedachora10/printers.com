<?php

namespace App\Actions\Loyalty;

use App\Enums\LoyaltyTransactionTypeEnum;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * يخصم نقاط الفاتورة من رصيد العميل فعلياً: يقفل صف العميل، ويعيد التحقق من
 * الرصيد (الحارس الأخير ضد استبدالين متزامنين)، ثم ينقص الرصيد ويكتب صفّ استبدالٍ
 * واحداً غير قابل للتعديل (نقاط سالبة)، ويختم الفاتورة بـ points_redeemed_at.
 *
 * لا يُستدعى إلا لحظة **اعتماد** الفاتورة: من MarkServiceInvoicePaidAction حين
 * يعتمد المحاسب فاتورة خدمة، ومن RecordInvoicePaymentAction حين يكتمل سداد
 * فاتورة منتجات، ومن مسار الإنشاء حين تُنشأ الفاتورة مدفوعةً من أول لحظة.
 * الفاتورة الآجلة تبقى نقاطها محجوزة (انظر ResolveAvailablePointsAction) حتى
 * يقع الاعتماد. والختم يجعل الاستدعاء غير مكرَّر الأثر مهما تعدّدت مساراته.
 */
class RedeemLoyaltyPointsAction
{
    public function handle(ProductInvoice|ServiceInvoice $invoice): ?LoyaltyTransaction
    {
        $points = (int) $invoice->points_redeemed;

        if ($points <= 0 || $invoice->customer_id === null || $invoice->points_redeemed_at !== null) {
            return null;
        }

        return DB::transaction(function () use ($invoice, $points) {
            /** @var Customer|null $customer */
            $customer = Customer::query()
                ->whereKey($invoice->customer_id)
                ->lockForUpdate()
                ->first();

            if (! $customer || $customer->points_balance < $points) {
                $available = (int) ($customer?->points_balance ?? 0);

                throw ValidationException::withMessages([
                    'redeem_points' => "رصيد نقاط العميل لم يعد كافياً لخصم النقاط المسجَّلة على الفاتورة (المطلوب {$points}، المتاح {$available}). عدّل الفاتورة بنقاطٍ أقل ثم اعتمدها.",
                ]);
            }

            $newBalance = $customer->points_balance - $points;
            $customer->update(['points_balance' => $newBalance]);

            // ختم لحظة الخصم: بعده لم تعد النقاط محجوزةً بل مخصومة، وهو نفسه ما
            // يجعل الإلغاء/الاسترجاع يعرف أن عليه ردَّها.
            $invoice->forceFill(['points_redeemed_at' => now()])->save();

            return LoyaltyTransaction::create([
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
                'invoice_type' => $invoice->getMorphClass(),
                'type' => LoyaltyTransactionTypeEnum::Redeem,
                'points' => -$points,
                'balance_after' => $newBalance,
            ]);
        });
    }
}
