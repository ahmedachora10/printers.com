<?php

namespace App\Actions\Agent;

use App\Enums\InvoiceStatusEnum;
use App\Models\Agent;
use App\Models\AgentPayment;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoiceAgent;
use Illuminate\Support\Facades\Auth;
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
        $branchId = (int) $data['branch_id'];

        return DB::transaction(function () use ($agent, $start, $end, $branchId, $data) {
            $window = [$start.' 00:00:00', $end.' 23:59:59'];

            // An agent may work with several branches, and each branch settles its
            // own invoices — so every row collected here must belong to the branch
            // being paid, or one branch would stamp another's dues as settled.
            // Product invoices still carry a single agent on the invoice row. Only
            // approved (paid) invoices are settled — a rebate is not payable while
            // the invoice is still due.
            $productRows = ProductInvoice::query()
                ->where('agent_id', $agent->id)
                ->where('branch_id', $branchId)
                ->whereNull('agent_payment_id')
                ->where('agent_rebate', '>', 0)
                ->where('status', InvoiceStatusEnum::PAID->value)
                ->whereBetween('created_at', $window)
                ->lockForUpdate()
                ->get(['id', 'agent_rebate']);

            // Service invoices may list several agents; each shares the rebate via
            // its own pivot row settled independently — again, paid invoices only.
            // A pivot row is payable for its rebate and/or its per-line commissions;
            // both are settled together under the same agent_payment_id stamp.
            $serviceRows = ServiceInvoiceAgent::query()
                ->where('agent_id', $agent->id)
                ->whereNull('agent_payment_id')
                ->where(fn ($q) => $q
                    ->where('rebate_amount', '>', 0)
                    ->orWhere('line_commission_amount', '>', 0))
                ->whereHas('invoice', fn ($q) => $q
                    ->where('status', InvoiceStatusEnum::PAID->value)
                    ->where('branch_id', $branchId)
                    ->whereBetween('created_at', $window))
                ->lockForUpdate()
                ->get(['id', 'rebate_amount', 'line_commission_amount']);

            $totalRebate = (float) $productRows->sum('agent_rebate')
                + (float) $serviceRows->sum('rebate_amount')
                + (float) $serviceRows->sum('line_commission_amount');
            $totalInvoices = $productRows->count() + $serviceRows->count();

            if ($totalInvoices === 0) {
                throw ValidationException::withMessages([
                    'agent_id' => 'لا توجد عمولات مستحقة لهذا المندوب في هذا الفرع خلال الفترة المحددة.',
                ]);
            }

            $payment = AgentPayment::create([
                'agent_id' => $agent->id,
                'branch_id' => $branchId,
                'period_start' => $start,
                'period_end' => $end,
                'total_invoices' => $totalInvoices,
                'total_rebate' => round($totalRebate, 2),
                'paid_by' => Auth::id(),
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
