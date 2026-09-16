<?php

namespace App\Actions\Shipping;

use App\Models\Expense;
use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تاسك 111 — «استلام مبلغ التوصيل»: يُسجَّل أجر السائق مصروفاً معتمداً مربوطاً
 * بالطلب وسائقه. النقدي يُطرح من نقد الدرج (تاسك 110) والتحويل لا يمسّه.
 * حالة الفاتورة (سداد العميل) لا تُمسّ.
 */
class SettleDeliveryAction
{
    /** @param array{amount: numeric, paid_from: string, expense_category_id: int} $data */
    public function handle(ServiceInvoice $invoice, array $data): Expense
    {
        return DB::transaction(function () use ($invoice, $data) {
            // القفل على الفاتورة يمنع تسويتين متزامنتين للطلب نفسه.
            ServiceInvoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($invoice->deliverySettlement()->exists()) {
                throw ValidationException::withMessages(['amount' => 'سُوّي هذا الطلب مسبقاً']);
            }

            $user = auth()->user();

            return Expense::query()->forceCreate([
                'expense_category_id' => $data['expense_category_id'],
                'branch_id' => $invoice->branch_id,
                'service_invoice_id' => $invoice->id,
                'delivery_provider_id' => $invoice->shipping_provider_id,
                'user_id' => $user->id,
                'qty' => 1,
                'unit_price' => $data['amount'],
                'total' => $data['amount'],
                'paid_from' => $data['paid_from'],
                'supplier_name' => $invoice->shippingProvider?->name,
                'comment' => "تسوية توصيل {$invoice->invoice_number}",
                'date' => today(),
                // من يسوّي هو من يعتمد (مدير الفرع/السوبر أدمن)، فيُحسب في التقارير فوراً.
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]);
        });
    }
}
