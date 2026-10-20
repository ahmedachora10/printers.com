<?php

namespace App\Actions\ServiceInvoice\Concerns;

use App\Enums\CommissionSourceTypeEnum;
use App\Enums\InvoiceStatusEnum;
use App\Models\CommissionLedger;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceHelper;
use App\Models\ServiceInvoiceLine;
use Carbon\CarbonInterface;

/**
 * Persists a service invoice's calculated line rows and, once the invoice is
 * approved (paid), their immutable commission-ledger entries. Shared by create
 * and edit so a line is always shaped identically. Call inside the surrounding
 * transaction.
 *
 * Line rows are written up front (they exist the moment the invoice does), but
 * the commission ledger is deferred until the invoice is paid — an employee's
 * commission is only earned once the accountant approves the invoice, mirroring
 * how loyalty points are credited only on paid invoices. So a due invoice never
 * writes a ledger row (nothing to reverse if it is later cancelled), and payment
 * writes the ledger from the persisted lines — each partial payment its share
 * (task 191), approval the rest.
 */
trait WritesServiceInvoiceLines
{
    /**
     * Persist the invoice's line rows (no ledger). The tahazir flag is snapshotted
     * onto the line so the ledger can be written faithfully later, at payment time.
     *
     * @param  list<array<string, mixed>>  $lines  rows from CalculateServiceInvoiceAction
     */
    protected function writeLines(ServiceInvoice $invoice, array $lines): void
    {
        foreach ($lines as $line) {
            $invoice->lines()->create([
                'branch_service_id' => $line['branch_service_id'],
                'service_name' => $line['service_name'],
                'notes' => $line['notes'] ?? null,
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'width_cm' => $line['width_cm'] ?? null,
                'height_cm' => $line['height_cm'] ?? null,
                // ما يقيسه unit_price: سعر متر لسطر مسعّر بالمتر، وسعر وحدة لغيره.
                'unit_price_basis' => $line['unit_price_basis'] ?? null,
                'discount_pct' => $line['discount_pct'],
                'subtotal' => $line['subtotal'],
                'commission_pct' => $line['commission_pct'],
                'commission_amount' => $line['commission_amount'],
                'materials_cost' => $line['materials_cost'] ?? 0,
                'materials_total' => $line['materials_total'] ?? 0,
                'is_tahazir' => $line['is_tahazir'],
                'agent_id' => $line['agent_id'] ?? null,
                'agent_commission_type' => $line['agent_commission_type'] ?? null,
                'agent_commission_value' => $line['agent_commission_value'] ?? null,
                'agent_commission_amount' => $line['agent_commission_amount'] ?? 0,
            ]);
        }
    }

    /**
     * المساعدون كما حسبتهم الحاسبة — لقطةٌ يُكتب منها صفّ عمولة كلٍّ منهم عند الاعتماد.
     *
     * @param  list<array<string, mixed>>  $helpers  rows from CalculateServiceInvoiceAction
     */
    protected function writeHelpers(ServiceInvoice $invoice, array $helpers): void
    {
        foreach ($helpers as $helper) {
            $invoice->helpers()->create($helper);
        }
    }

    /**
     * تاسك 191: العمولة تتبع المحصَّل. لكل سطر (لصاحب الفاتورة) ولكل مساعد الهدفُ =
     * عمولته × صافي المحصَّل ÷ الإجمالي — وعمولته كاملةً بالضبط متى بلغ الإجمالي —
     * ويُكتب الفرق عمّا في الدفتر صفّاً غير قابل للتعديل. idempotent: استدعاءٌ ثانٍ
     * لا يكتب شيئاً، فيمرّ به الجميع (الدفعة، الاعتماد، الخصم، التعديل، المرتجع).
     *
     * تُمشى الدفعات بترتيب تاريخها فيُؤرَّخ نصيب كلٍّ منها بتاريخها (البند 4)، ثم
     * خطوةٌ أخيرة بتاريخ $at تُطابق الهدف بالضبط. الفرق السالب يُكتب في الأخيرة
     * وحدها ولا يتجاوز غير المصروف (M14: ما صُرف لا يُستردّ). المعتمَدة محصَّلها
     * إجماليُّها، والمرتجعات تُطرح منه. داخل معاملة المتصل.
     */
    public function syncCommissionLedger(ServiceInvoice $invoice, CarbonInterface $at): void
    {
        $total = round((float) $invoice->total_amount, 2);
        $payments = $invoice->payments()->orderBy('paid_at')->orderBy('id')->get(['amount', 'paid_at']);
        $net = round(($invoice->status === InvoiceStatusEnum::PAID ? $total : (float) $payments->sum('amount'))
            - (float) $invoice->refunds()->sum('amount'), 2);

        $key = fn (string $type, int $id, int $userId) => $type.'#'.$id.'#'.$userId;
        $entries = $invoice->ledgerEntries()->lockForUpdate()->get();
        $group = fn ($rows) => $rows->groupBy(fn (CommissionLedger $e) => $key($e->invoice_line_type, $e->invoice_line_id, $e->user_id))
            ->map(fn ($g) => round((float) $g->sum('amount'), 2))->all();
        $written = $group($entries);
        $unpaid = $group($entries->whereNull('paid_at'));

        $owed = [
            ...$invoice->helpers()->get()->map(fn ($h) => [$h, ServiceInvoiceHelper::class, $h->user_id]),
            ...$invoice->lines()->get()->map(fn ($l) => [$l, ServiceInvoiceLine::class, (int) $invoice->user_id]),
        ];

        $steps = [];
        $running = 0.0;
        foreach ($payments as $payment) {
            $steps[] = [$payment->paid_at, $running = round($running + (float) $payment->amount, 2)];
        }
        $steps[] = [$at, $net];
        $last = count($steps) - 1;

        foreach ($steps as $i => [$date, $collected]) {
            $ratio = $total > 0 ? min(max(min($collected, $net), 0) / $total, 1.0) : 1.0;

            foreach ($owed as [$row, $type, $userId]) {
                $k = $key($type, $row->id, $userId);
                $full = (float) $row->commission_amount;
                $delta = round(($ratio >= 1.0 ? $full : round($full * $ratio, 2)) - ($written[$k] ?? 0), 2);

                if ($delta < 0) {
                    if ($i < $last) {
                        continue;
                    }

                    $delta = -min(-$delta, max($unpaid[$k] ?? 0, 0));
                }

                // سطرٌ بلا عمولة يُكتب صفُّه الصفري مرّة، كما كان الاعتماد يكتبه.
                if ($delta == 0 && ! ($i === $last && ! isset($written[$k]) && $full == 0)) {
                    continue;
                }

                CommissionLedger::create([
                    'user_id' => $userId,
                    'branch_id' => $invoice->branch_id,
                    'invoice_line_id' => $row->id,
                    'invoice_line_type' => $type,
                    'amount' => $delta,
                    'is_tahazir' => $row->is_tahazir,
                    'tier_applied' => null,
                    'source_type' => CommissionSourceTypeEnum::STANDARD,
                    'earned_at' => $date,
                ]);

                $written[$k] = round(($written[$k] ?? 0) + $delta, 2);
                $unpaid[$k] = round(($unpaid[$k] ?? 0) + $delta, 2);
            }
        }
    }
}
