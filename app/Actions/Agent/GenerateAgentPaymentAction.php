<?php

namespace App\Actions\Agent;

use App\Models\Agent;
use App\Models\AgentCommissionEntry;
use App\Models\AgentPayment;
use Illuminate\Support\Carbon;
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
            // تاسك 217: ما استحقّه المندوب في الفترة من دفتره — كلُّ قيدٍ بتاريخ
            // التحصيل الذي كتبه (دفعة، اعتماد، مرتجع)، لا بتاريخ إنشاء الفاتورة.
            // كل فرعٍ يصرف قيود فواتيره وحده، وما صُرف مختومٌ فلا يُصرف مرتين.
            $entries = AgentCommissionEntry::query()
                ->where('agent_id', $agent->id)
                ->where('branch_id', $branchId)
                ->whereNull('agent_payment_id')
                ->where(fn ($q) => $q->where('rebate_amount', '!=', 0)->orWhere('line_commission_amount', '!=', 0))
                ->whereBetween('earned_at', [$start.' 00:00:00', $end.' 23:59:59'])
                ->lockForUpdate()
                ->get(['id', 'invoice_type', 'invoice_id', 'rebate_amount', 'line_commission_amount']);

            $total = round((float) $entries->sum('rebate_amount') + (float) $entries->sum('line_commission_amount'), 2);

            if ($total <= 0) {
                throw ValidationException::withMessages([
                    'agent_id' => 'لا توجد عمولات مستحقة لهذا المندوب في هذا الفرع خلال الفترة المحددة.',
                ]);
            }

            $payment = AgentPayment::create([
                'agent_id' => $agent->id,
                'branch_id' => $branchId,
                'period_start' => $start,
                'period_end' => $end,
                'total_invoices' => $entries->unique(fn ($e) => $e->invoice_type.'#'.$e->invoice_id)->count(),
                'total_rebate' => $total,
                'paid_by' => Auth::id(),
                // تاسك 232: اليوم المختار بوقت الآن — فيبقى ترتيب دفعات اليوم الواحد.
                'paid_at' => Carbon::parse($data['paid_at'] ?? now())->setTimeFrom(now()),
                'notes' => $data['notes'] ?? null,
            ]);

            AgentCommissionEntry::whereIn('id', $entries->pluck('id'))->update(['agent_payment_id' => $payment->id]);

            return $payment;
        });
    }
}
