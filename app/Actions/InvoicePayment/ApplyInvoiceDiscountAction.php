<?php

namespace App\Actions\InvoicePayment;

use App\Actions\Agent\ResolveInvoiceAgentAction;
use App\Actions\Incentive\RecalculateIncentivePlanAction;
use App\Actions\ProductInvoice\CreateProductInvoiceAction;
use App\Actions\ServiceInvoice\CalculateServiceInvoiceAction;
use App\Enums\AgentDiscountModeEnum;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use Illuminate\Validation\ValidationException;

/**
 * خصمٌ إضافي على فاتورة لم يكتمل سدادها — المثال الذي جاء به العميل: فاتورة
 * 200، عربون 100، ثم اتُّفق على 180 فدفع 80. يُطرح بعد النقاط وقبل الشحن، فتُعاد
 * الضريبة على الإجمالي الجديد، وتُعاد عمولة الموظف وريبيت المندوب على الصافي
 * الجديد — كأي خصم آخر في السلسلة.
 *
 * يُعاد الحساب من الأرقام المحفوظة لا بإعادة تشغيل الحاسبة كاملة: كوبونٌ انتهى
 * أو سعرٌ تغيّر بعد البيع لا شأن لهما بخصمٍ يُتّفق عليه عند التحصيل.
 *
 * `$delta` يُضاف إلى الخصم القائم، والسالب تصحيحٌ له — كتصحيح الدفعات. ولا يجوز
 * أن ينزل الإجمالي تحت ما حُصِّل: ذاك استرجاعٌ لا خصم.
 *
 * لا معاملة هنا ولا قفل: المستدعي (RecordInvoicePaymentAction) يقفل صف الفاتورة
 * ويقرّر الإغلاق إن غطّى المحصَّلُ الإجماليَّ الجديد.
 */
class ApplyInvoiceDiscountAction
{
    public function __construct(
        private readonly CalculateServiceInvoiceAction $serviceCalculator,
        private readonly CreateProductInvoiceAction $productCalculator,
        private readonly ResolveInvoiceAgentAction $resolveAgent,
        private readonly RecalculateIncentivePlanAction $recalculateIncentive,
    ) {}

    public function handle(ProductInvoice|ServiceInvoice $invoice, float $delta): void
    {
        if (! $invoice->status->acceptsPayment()) {
            throw ValidationException::withMessages([
                'discount' => 'لا يُضاف خصم إلا على فاتورة آجلة أو مدفوعة جزئياً.',
            ]);
        }

        // ريبيتٌ دخل دفعة مندوب لا يُعاد حسابه من تحتها.
        $inAgentPayment = $invoice instanceof ServiceInvoice
            ? $invoice->invoiceAgents()->whereNotNull('agent_payment_id')->exists()
            : $invoice->agent_payment_id !== null;

        if ($inAgentPayment) {
            throw ValidationException::withMessages([
                'discount' => 'لا يمكن الخصم على فاتورة مُدرجة ضمن دفعة مندوب.',
            ]);
        }

        $shipping = $invoice instanceof ServiceInvoice ? (float) $invoice->shipping_fee : 0.0;
        $beforeManual = $invoice->discountBase();
        $manual = round((float) $invoice->manual_discount + $delta, 2);

        if ($manual < 0 || $manual > $beforeManual) {
            throw ValidationException::withMessages([
                'discount' => 'الخصم يجب أن يكون بين صفر و'.number_format($beforeManual, 2).' ر.س.',
            ]);
        }

        $vatPct = (float) $invoice->vat_pct;
        $servicesTotal = round($beforeManual - $manual, 2);
        $total = round($servicesTotal + $shipping, 2);
        $collected = round((float) $invoice->payments()->sum('amount'), 2);

        if ($total < $collected) {
            throw ValidationException::withMessages([
                'discount' => 'الإجمالي بعد الخصم أقل مما حُصِّل ('.number_format($collected, 2).' ر.س) — هذا استرجاع لا خصم.',
            ]);
        }

        $net = round($total / (1 + $vatPct / 100), 2);

        $invoice->fill([
            'manual_discount' => $manual,
            'total_amount' => $total,
            'vat_amount' => round($total - $net, 2),
        ]);

        $invoice instanceof ServiceInvoice
            ? $this->repriceService($invoice, round($servicesTotal / (1 + $vatPct / 100), 2))
            : $this->repriceProduct($invoice, $net);

        $invoice->save();

        // تاسك 160: المدفوعة جزئياً داخلةٌ في المحقَّق، فيتبع الإجماليَّ الجديد.
        if ($invoice instanceof ServiceInvoice) {
            $this->recalculateIncentive->refreshForInvoice($invoice);
        }
    }

    /** العمولة والريبيت على مال الخدمات صافياً — نفس ما تفعله الحاسبة. */
    private function repriceService(ServiceInvoice $invoice, float $servicesNet): void
    {
        $lines = $invoice->lines()->get()->keyBy('id');
        $commissions = $this->serviceCalculator->lineCommissions($lines, $servicesNet, (float) $invoice->subtotal);

        foreach ($lines as $id => $line) {
            $line->update(['commission_amount' => $commissions[$id]]);
        }

        $invoice->employee_commission = round(array_sum($commissions), 2);

        // صفوف المندوب المضافة من السطور وحدها ريبيتها صفرٌ عمداً — لا تُمسّ.
        $invoice->invoiceAgents()
            ->where('discount_mode', AgentDiscountModeEnum::Rebate)
            ->where('rebate_amount', '>', 0)
            ->get()
            ->each(fn ($row) => $row->update([
                'rebate_amount' => $this->serviceCalculator->agentAmount($row->discount_type, (float) $row->rate, $servicesNet),
            ]));
    }

    /** شروط المندوب تُقرأ حيّةً من ربط الفرع، كما يفعل تعديل فاتورة المنتجات. */
    private function repriceProduct(ProductInvoice $invoice, float $net): void
    {
        if ((float) $invoice->agent_rebate <= 0) {
            return;
        }

        [, $mode, $type, $rate] = $this->resolveAgent->handle((int) $invoice->agent_id, (int) $invoice->branch_id);

        $invoice->agent_rebate = $mode === AgentDiscountModeEnum::Rebate
            ? $this->productCalculator->agentAmount($type, $rate, $net)
            : 0.0;
    }
}
