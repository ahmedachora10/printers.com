<?php

namespace App\Actions\Agent;

use App\Enums\InvoiceStatusEnum;
use App\Models\Agent;
use App\Models\AgentPayment;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateAgentPaymentAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): AgentPayment
    {
        // The Agent global scope guarantees this id belongs to an agent user.
        $agent = Agent::findOrFail((int) $data['agent_id']);
        $start = (string) $data['period_start'];
        $end = (string) $data['period_end'];

        return DB::transaction(function () use ($agent, $start, $end, $data) {
            $totalRebate = 0.0;
            $totalInvoices = 0;
            /** @var array<class-string<ProductInvoice|ServiceInvoice>, array<int, int>> $coveredIds */
            $coveredIds = [];

            foreach ([ProductInvoice::class, ServiceInvoice::class] as $model) {
                $rows = $model::query()
                    ->where('agent_id', $agent->id)
                    ->whereNull('agent_payment_id')
                    ->where('agent_rebate', '>', 0)
                    ->where('status', '!=', InvoiceStatusEnum::CANCELLED->value)
                    ->whereBetween('created_at', [$start.' 00:00:00', $end.' 23:59:59'])
                    ->lockForUpdate()
                    ->get(['id', 'agent_rebate']);

                $totalRebate += (float) $rows->sum('agent_rebate');
                $totalInvoices += $rows->count();
                $coveredIds[$model] = $rows->pluck('id')->all();
            }

            if ($totalInvoices === 0) {
                throw ValidationException::withMessages([
                    'agent_id' => 'لا توجد عمولات مستحقة لهذا الوكيل في الفترة المحددة.',
                ]);
            }

            $payment = AgentPayment::create([
                'agent_id' => $agent->id,
                'branch_id' => $agent->branch_id,
                'period_start' => $start,
                'period_end' => $end,
                'total_invoices' => $totalInvoices,
                'total_rebate' => round($totalRebate, 2),
                'paid_by' => auth()->id(),
                'paid_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($coveredIds as $model => $ids) {
                if ($ids !== []) {
                    $model::whereIn('id', $ids)->update(['agent_payment_id' => $payment->id]);
                }
            }

            return $payment;
        });
    }
}
