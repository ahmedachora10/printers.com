<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\Loyalty\EarnLoyaltyPointsAction;
use App\Actions\Loyalty\RedeemLoyaltyPointsAction;
use App\Actions\ServiceInvoice\Concerns\WritesServiceInvoiceLines;
use App\Enums\InvoiceStatusEnum;
use App\Models\ServiceInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settles a service invoice: marks it paid, stamps paid_at, writes the
 * employee's commission ledger, spends the points the customer redeemed on it,
 * credits the points it earned, and draws the services' materials out of stock.
 * Commission, points (redeemed and earned) and stock are all only realised once
 * the invoice becomes paid (approved).
 *
 * This is the single approval path. It is reached either by an accountant
 * approving the whole invoice from the review queue, or by the final instalment
 * completing an invoice that was being paid off (عربون + دفعات لاحقة) — in which
 * case RecordInvoicePaymentAction calls it with that last payment's moment as
 * $paidAt. ولأنه المسار الوحيد، فحصُ كفاية الخامات موضوعٌ فيه لا في المتحكِّم:
 * الاعتمادُ وإكمالُ الدفعة كلاهما يمرّ منه. Only an invoice that still awaits money (due or partially paid) can
 * be settled here, so the ledger and the points are never written twice.
 */
class MarkServiceInvoicePaidAction
{
    use WritesServiceInvoiceLines;

    public function __construct(
        private readonly EarnLoyaltyPointsAction $earnLoyaltyPoints,
        private readonly RedeemLoyaltyPointsAction $redeemLoyaltyPoints,
        private readonly ConsumeServiceMaterialsAction $consumeMaterials,
        private readonly ResolveMaterialsRequirementAction $resolveRequirement,
    ) {}

    public function handle(
        ServiceInvoice $invoice,
        ?CarbonInterface $paidAt = null,
        bool $confirmedShortage = false,
    ): ServiceInvoice {
        if (! $invoice->status->acceptsPayment()) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن اعتماد الدفع إلا لفاتورة آجلة أو مدفوعة جزئياً.',
            ]);
        }

        // كفايةُ المخزون تُفحص قبل فتح المعاملة: العجز ليس منعاً بل وقفةٌ يقرّها
        // المعتمِد صراحةً، فالشغل قد سُلِّم للعميل فعلاً ورفضُ اعتماده يعلّق
        // الفاتورة في قائمة المراجعة بلا مخرج. أما المرور صامتاً فيُخفي أن
        // الرصيد صار سالباً.
        $requirement = $this->resolveRequirement->forInvoice($invoice);

        if (! $confirmedShortage && $requirement->hasShortage()) {
            throw ValidationException::withMessages([
                'materials_shortage' => $requirement->message(),
            ]);
        }

        if ($requirement->hasShortage()) {
            activity('inventory')
                ->performedOn($invoice)
                ->causedBy(auth()->user())
                ->withProperties(['shortages' => $requirement->shortages()->all()])
                ->log("اعتماد الفاتورة {$invoice->invoice_number} رغم عجز الخامات");
        }

        return DB::transaction(function () use ($invoice, $paidAt) {
            $invoice->update([
                'status' => InvoiceStatusEnum::PAID,
                // فاتورة سُدِّدت على دفعات: تاريخ السداد هو لحظة آخر دفعة، لا لحظة الاعتماد.
                'paid_at' => $paidAt ?? now(),
            ]);

            // The invoice is now approved, so the employee earns their commission:
            // write the immutable ledger rows from the persisted lines.
            $this->writeLedgerFromLines($invoice, (int) $invoice->user_id, (int) $invoice->branch_id);

            // الآن وقد اعتُمدت الفاتورة تُخصم نقاطها المحجوزة من رصيد العميل: قبل
            // الاعتماد كان الخصم ظاهراً على الفاتورة فقط والرصيد لم يُمسّ.
            // الخصم قبل الاكتساب، فيقرأ الاكتساب رصيداً محدَّثاً.
            $this->redeemLoyaltyPoints->handle($invoice);

            // Credit points now that the invoice is paid; no-ops for ineligible
            // customers (corporate, agent-linked, or inactive loyalty config).
            $this->earnLoyaltyPoints->handle($invoice);

            // تاسك 50: الخدمة المنفَّذة استهلكت خاماتها — تُخصم من المخزون الآن.
            // المُنفِّذ هنا هو المعتمِد (المحاسب/مدير الفرع) لا صاحب الفاتورة.
            $this->consumeMaterials->consume($invoice, (int) (auth()->id() ?? $invoice->user_id));

            return $invoice;
        });
    }
}
