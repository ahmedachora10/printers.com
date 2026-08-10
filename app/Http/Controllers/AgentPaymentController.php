<?php

namespace App\Http\Controllers;

use App\Actions\Agent\GenerateAgentPaymentAction;
use App\Enums\InvoiceStatusEnum;
use App\Http\Requests\Agent\StoreAgentPaymentRequest;
use App\Http\Resources\Agent\AgentPaymentResource;
use App\Models\Agent;
use App\Models\AgentPayment;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoiceAgent;
use App\Notifications\AgentCommissionPaidNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AgentPaymentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AgentPayment::class);

        $branchId = auth()->user()->branchId ?? null;

        $outstanding = $this->outstandingByAgentBranch($branchId);

        // One settlement row per (agent × branch): an agent may work with several
        // branches and each is paid separately, so a single merged figure would
        // invite settling one branch against another's total.
        $agents = Agent::query()
            ->when($branchId, fn ($q) => $q->forBranch($branchId))
            ->with('agentBranches:id,name')
            ->orderBy('name')
            ->get()
            ->flatMap(fn (Agent $agent) => $agent->agentBranches
                ->when($branchId, fn ($branches) => $branches->where('id', $branchId))
                ->map(fn (Branch $branch) => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'isActive' => $agent->is_active,
                    'branchId' => $branch->id,
                    'branchName' => $branch->name,
                    'outstandingRebate' => round($outstanding[$agent->id.'-'.$branch->id]['rebate'] ?? 0, 2),
                    'outstandingInvoices' => $outstanding[$agent->id.'-'.$branch->id]['count'] ?? 0,
                ]))
            ->values();

        $payments = AgentPayment::query()
            ->with(['agent:id,name', 'branch:id,name', 'paidBy:id,name'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('paid_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('agent-payments/index', [
            'agents' => $agents,
            // Resource collection => { data, links, meta } shape the page expects.
            'payments' => AgentPaymentResource::collection($payments),
            'branches' => Auth::user()->roleName?->isSuperAdmin()
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : null,
        ]);
    }

    public function store(StoreAgentPaymentRequest $request, GenerateAgentPaymentAction $action): RedirectResponse
    {
        Gate::authorize('create', AgentPayment::class);

        $agent = Agent::findOrFail((int) $request->validated('agent_id'));
        Gate::authorize('pay', $agent);

        $payment = $action->handle($request->validated());

        // Mirrors CommissionController::pay() — the payee learns of the payout.
        $agent->notify(new AgentCommissionPaidNotification($payment->load('branch')));

        return back(fallback: route('agent-payments.index'))->with('success', 'تم تسجيل دفعة العمولة بنجاح');
    }

    /**
     * Sum of unpaid rebate (and invoice count) per agent *per branch*, within
     * scope, keyed "agentId-branchId".
     *
     * The branch dimension is not cosmetic: each branch settles its own invoices,
     * so a figure merged across branches could never be paid in one run.
     *
     * @return array<string, array{rebate: float, count: int}>
     */
    private function outstandingByAgentBranch(?int $branchId): array
    {
        $outstanding = [];

        $add = function (int $agentId, int $rowBranchId, float $rebate, int $count) use (&$outstanding) {
            $key = $agentId.'-'.$rowBranchId;
            $outstanding[$key]['rebate'] = ($outstanding[$key]['rebate'] ?? 0) + $rebate;
            $outstanding[$key]['count'] = ($outstanding[$key]['count'] ?? 0) + $count;
        };

        // Only approved (paid) invoices count as outstanding rebate — a due
        // invoice is not payable, so it must not inflate the agent's balance here.
        // Product invoices carry a single agent on the invoice row.
        ProductInvoice::query()
            ->whereNotNull('agent_id')
            ->whereNull('agent_payment_id')
            ->where('agent_rebate', '>', 0)
            ->where('status', InvoiceStatusEnum::PAID->value)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('agent_id', 'branch_id')
            ->selectRaw('agent_id, branch_id, SUM(agent_rebate) as rebate, COUNT(*) as cnt')
            ->get()
            ->each(fn ($row) => $add((int) $row->agent_id, (int) $row->branch_id, (float) $row->rebate, (int) $row->cnt));

        // Service invoices settle each agent independently via the pivot. The
        // payable per row is the invoice-level rebate plus any per-line
        // commissions accrued to the agent on that invoice. The branch lives on
        // the invoice, so join rather than filter through whereHas.
        ServiceInvoiceAgent::query()
            ->join('service_invoices', 'service_invoices.id', '=', 'service_invoice_agent.service_invoice_id')
            ->whereNull('service_invoices.deleted_at')
            ->whereNull('service_invoice_agent.agent_payment_id')
            ->where(fn ($q) => $q
                ->where('service_invoice_agent.rebate_amount', '>', 0)
                ->orWhere('service_invoice_agent.line_commission_amount', '>', 0))
            ->where('service_invoices.status', InvoiceStatusEnum::PAID->value)
            ->when($branchId, fn ($q) => $q->where('service_invoices.branch_id', $branchId))
            ->groupBy('service_invoice_agent.agent_id', 'service_invoices.branch_id')
            ->selectRaw('service_invoice_agent.agent_id, service_invoices.branch_id,
                SUM(service_invoice_agent.rebate_amount + service_invoice_agent.line_commission_amount) as rebate,
                COUNT(*) as cnt')
            ->get()
            ->each(fn ($row) => $add((int) $row->agent_id, (int) $row->branch_id, (float) $row->rebate, (int) $row->cnt));

        return $outstanding;
    }
}
