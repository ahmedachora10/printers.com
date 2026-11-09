<?php

namespace App\Http\Controllers;

use App\Actions\Report\ResolveReportScope;
use App\Models\AgentCommissionEntry;
use App\Models\AgentPayment;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AgentPortalController extends Controller
{
    public function index(Request $request, ResolveReportScope $scope): Response
    {
        $agent = Auth::user();
        $agent->loadMissing(['agentProfile', 'branch:id,name', 'agentBranches:id,name']);

        // Same convention as the reports: an unfiltered portal shows today only,
        // and the agent widens the range from the bar above the tables.
        ['from' => $from, 'to' => $to] = $scope->handle($request);

        // An agent may work with several branches. The portal shows them merged
        // — that is the whole point of one account across branches — with an
        // optional narrowing to one of them.
        $branchId = $request->filled('branch')
            && $agent->agentBranches->contains('id', (int) $request->input('branch'))
                ? (int) $request->input('branch')
                : null;

        // تاسك 217: من دفتر المندوب — كل مبلغ بتاريخ تحصيله، والجزئية بقدر كل دفعة.
        // صفٌّ لكل فاتورة، الأحدث تحصيلاً أولاً؛ والقيود المختومة هي المصروف.
        $perInvoice = AgentCommissionEntry::perInvoice(AgentCommissionEntry::query()
            ->where('agent_id', $agent->id)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('earned_at', [$from, $to]));

        $earned = round((float) $perInvoice->sum('rebate') + (float) $perInvoice->sum('line_commission'), 2);
        $paid = round((float) $perInvoice->sum('paid'), 2);
        $summary = [
            'invoiceCount' => $perInvoice->count(),
            'rebateEarned' => $earned,
            'rebatePaid' => $paid,
            'discountGiven' => round((float) $perInvoice->sum('discount'), 2),
            'rebateOutstanding' => round($earned - $paid, 2),
        ];

        $recentInvoices = $perInvoice->take(20)->map(fn (AgentCommissionEntry $row) => $row->invoiceRow())->values();

        $payments = AgentPayment::query()
            ->where('agent_id', $agent->id)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('branch:id,name')
            ->whereBetween('paid_at', [$from, $to])
            ->orderByDesc('paid_at')
            ->limit(20)
            ->get()
            ->map(fn (AgentPayment $p) => [
                'branchName' => $p->branch?->name,
                'periodStart' => $p->period_start->format('d/m/Y'),
                'periodEnd' => $p->period_end->format('d/m/Y'),
                'totalInvoices' => $p->total_invoices,
                'totalRebate' => (float) $p->total_rebate,
                'paidAt' => $p->paid_at?->format('d/m/Y'),
                'notes' => $p->notes,
            ]);

        return Inertia::render('agent-portal/index', [
            'agent' => [
                'name' => $agent->name,
                // Terms differ per branch, so a single rate would be a lie for a
                // multi-branch agent: send the whole list and let the page show it.
                'branches' => $agent->agentBranches->map(fn (Branch $branch) => [
                    'branchId' => $branch->id,
                    'branchName' => $branch->name,
                    'discountMode' => $branch->pivot->discount_mode?->value,
                    'discountModeLabel' => $branch->pivot->discount_mode?->label(),
                    'discountType' => $branch->pivot->discount_type?->value ?? 'percentage',
                    'rate' => (float) $branch->pivot->rate,
                ])->values(),
            ],
            'summary' => $summary,
            'recentInvoices' => $recentInvoices,
            'payments' => $payments,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'branch' => $branchId ? (string) $branchId : null,
            ],
            'defaultDate' => now()->toDateString(),
        ]);
    }
}
