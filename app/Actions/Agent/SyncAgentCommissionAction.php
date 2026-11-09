<?php

namespace App\Actions\Agent;

use App\Models\AgentCommissionEntry;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use Carbon\CarbonInterface;

/**
 * تاسك 217 — نصيب المندوب يتبع المحصَّل، دفعةً دفعة وبتاريخها، كعمولة الموظف
 * (تاسك 191) وعلى خطواتها نفسها (collectionSteps): فاتورة أكتوبر المسدَّدة في
 * نوفمبر عمولتها في نوفمبر، والجزئية بقدر كل دفعة.
 *
 * لكل مندوب على الفاتورة الهدفُ = مبلغه كاملاً × نسبة المحصَّل (كاملاً بالضبط متى
 * بلغت 1)، والفرق عمّا في دفتره قيدٌ جديد. idempotent، فيمرّ به كل ما يحرّك
 * المحصَّل أو المبالغ. السالب في الخطوة الأخيرة وحدها، ولا يتجاوز في المستحق
 * غير المصروف (ما صُرف لا يُستردّ). مندوبٌ أُزيل بتعديلٍ هدفه صفر. داخل معاملة المتصل.
 */
class SyncAgentCommissionAction
{
    public function handle(ProductInvoice|ServiceInvoice $invoice, CarbonInterface $at): void
    {
        $owed = $this->owed($invoice);
        $entries = $invoice->agentCommissionEntries()->lockForUpdate()->get();
        $sums = fn ($rows) => $rows->groupBy('agent_id')
            ->map(fn ($g) => array_map(fn ($c) => round((float) $g->sum($c), 2), array_combine(AgentCommissionEntry::AMOUNTS, AgentCommissionEntry::AMOUNTS)))
            ->all();
        $written = $sums($entries);
        $unpaid = $sums($entries->whereNull('agent_payment_id'));
        $owed += array_fill_keys(array_keys($written), array_fill_keys(AgentCommissionEntry::AMOUNTS, 0.0));

        if ($owed === []) {
            return;
        }

        $steps = $invoice->collectionSteps($at);
        $last = count($steps) - 1;

        foreach ($steps as $i => [$date, $ratio]) {
            foreach ($owed as $agentId => $full) {
                $delta = [];

                foreach ($full as $column => $amount) {
                    $d = round(($ratio >= 1.0 ? $amount : round($amount * $ratio, 2)) - ($written[$agentId][$column] ?? 0), 2);

                    if ($d < 0 && $i < $last) {
                        $d = 0.0;
                    } elseif ($d < 0 && in_array($column, AgentCommissionEntry::PAYABLE, true)) {
                        $d = -min(-$d, max($unpaid[$agentId][$column] ?? 0, 0));
                    }

                    $delta[$column] = $d;
                }

                if (array_filter($delta) === []) {
                    continue;
                }

                $invoice->agentCommissionEntries()->create([
                    'agent_id' => $agentId,
                    'branch_id' => $invoice->branch_id,
                    ...$delta,
                    'earned_at' => $date,
                ]);

                foreach ($delta as $column => $d) {
                    $written[$agentId][$column] = round(($written[$agentId][$column] ?? 0) + $d, 2);
                    $unpaid[$agentId][$column] = round(($unpaid[$agentId][$column] ?? 0) + $d, 2);
                }
            }
        }
    }

    /**
     * ما للمندوب على الفاتورة كاملاً: فاتورة الخدمات لكل مندوب صفٌّ (قد يتعدّدون)،
     * وفاتورة المنتجات مندوبٌ واحد على صفّها، بلا عمولة بنود.
     *
     * @return array<int, array<string, float>> agent_id ⇒ عمود ⇒ المبلغ
     */
    private function owed(ProductInvoice|ServiceInvoice $invoice): array
    {
        $total = (float) $invoice->total_amount;

        if ($invoice instanceof ProductInvoice) {
            return $invoice->agent_id === null ? [] : [(int) $invoice->agent_id => [
                'sales_amount' => $total,
                'discount_amount' => (float) $invoice->agent_discount,
                'rebate_amount' => (float) $invoice->agent_rebate,
                'line_commission_amount' => 0.0,
            ]];
        }

        return $invoice->invoiceAgents()->get()->groupBy('agent_id')->map(fn ($rows) => [
            'sales_amount' => $total,
            'discount_amount' => (float) $rows->sum('discount_amount'),
            'rebate_amount' => (float) $rows->sum('rebate_amount'),
            'line_commission_amount' => (float) $rows->sum('line_commission_amount'),
        ])->all();
    }
}
