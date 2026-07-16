<?php

namespace App\Actions\Agent;

use App\Enums\InvoiceStatusEnum;
use App\Models\Agent;
use App\Models\AgentPayment;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoiceAgent;
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
            $window = [$start.' 00:00:00', $end.' 23:59:59'];

            // Product invoices still carry a single agent on the invoice row. Only
            // approved (paid) invoices are settled — a rebate is not payable while
            // the invoice is still due.
            $productRows = ProductInvoice::query()
                ->where('agent_id', $agent->id)
                ->whereNull('agent_payment_id')
                ->where('agent_rebate', '>', 0)
                ->where('status', InvoiceStatusEnum::PAID->value)
                ->whereBetween('created_at', $window)
                ->lockForUpdate()
                ->get(['id', 'agent_rebate']);

            // Service invoices may list several agents; each shares the rebate via
            // its own pivot row settled independently — again, paid invoices only.
            $serviceRows = ServiceInvoiceAgent::query()
                ->where('agent_id', $agent->id)
                ->whereNull('agent_payment_id')
                ->where('rebate_amount', '>', 0)
                ->whereHas('invoice', fn ($q) => $q
                    ->where('status', InvoiceStatusEnum::PAID->value)
                    ->whereBetween('created_at', $window))
                ->lockForUpdate()
                ->get(['id', 'rebate_amount']);

            $totalRebate = (float) $productRows->sum('agent_rebate') + (float) $serviceRows->sum('rebate_amount');
            $totalInvoices = $productRows->count() + $serviceRows->count();

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

            if ($productRows->isNotEmpty()) {
                ProductInvoice::whereIn('id', $productRows->pluck('id'))->update(['agent_payment_id' => $payment->id]);
            }

            if ($serviceRows->isNotEmpty()) {
                ServiceInvoiceAgent::whereIn('id', $serviceRows->pluck('id'))->update(['agent_payment_id' => $payment->id]);
            }

            return $payment;
        });
    }
}
