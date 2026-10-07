<?php

namespace App\Actions\Shipping;

use App\Models\Expense;
use App\Models\ServiceInvoiceShipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تاسك 111 — «تسليم مبلغ التوصيل»: يُسجَّل أجر السائق مصروفاً معتمداً مربوطاً
 * بالطلب وسائقه. النقدي يُطرح من نقد الدرج (تاسك 110) والتحويل لا يمسّه.
 * حالة الفاتورة (سداد العميل) لا تُمسّ.
 *
 * تاسك 170: التسوية لكل طلب توصيل — فاتورةٌ بسائقين تُسوّى مع كلٍّ على حدة.
 */
class SettleDeliveryAction
{
    /** @param array{amount: numeric, paid_from: string, expense_category_id: int} $data */
    public function handle(ServiceInvoiceShipment $shipment, array $data): Expense
    {
        return DB::transaction(function () use ($shipment, $data) {
            // القفل على الطلب يمنع تسويتين متزامنتين له.
            ServiceInvoiceShipment::whereKey($shipment->id)->lockForUpdate()->first();

            if ($shipment->settlement()->exists()) {
                throw ValidationException::withMessages(['amount' => 'سُوّي هذا الطلب مسبقاً']);
            }

            $user = auth()->user();
            $invoice = $shipment->invoice;

            return Expense::query()->forceCreate([
                'expense_category_id' => $data['expense_category_id'],
                'branch_id' => $invoice->branch_id,
                'service_invoice_id' => $invoice->id,
                'service_invoice_shipment_id' => $shipment->id,
                'delivery_provider_id' => $shipment->provider_id,
                'user_id' => $user->id,
                'qty' => 1,
                'unit_price' => $data['amount'],
                'total' => $data['amount'],
                'paid_from' => $data['paid_from'],
                'supplier_name' => $shipment->provider?->name,
                'comment' => "تسوية توصيل {$invoice->invoice_number}",
                'date' => today(),
                // من يسوّي هو من يعتمد (مدير الفرع/السوبر أدمن)، فيُحسب في التقارير فوراً.
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]);
        });
    }
}
