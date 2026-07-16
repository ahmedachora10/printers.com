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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AgentPaymentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AgentPayment::class);

        $branchId = auth()->user()->branchId ?? null;

        $outstanding = $this->outstandingByAgent($branchId);

        $agents = Agent::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get()
            ->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'isActive' => $agent->is_active,
                'outstandingRebate' => round($outstanding[$agent->id]['rebate'] ?? 0, 2),
                'outstandingInvoices' => $outstanding[$agent->id]['count'] ?? 0,
            ])
            ->values();

        $payments = AgentPayment::query()
            ->with(['agent:id,name', 'paidBy:id,name'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('paid_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('agent-payments/index', [
            'agents' => $agents,
            // Resource collection => { data, links, meta } shape the page expects.
            'payments' => AgentPaymentResource::collection($payments),
            'branches' => auth()->user()->roleName?->isSuperAdmin()
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : null,
        ]);
    }

    public function store(StoreAgentPaymentRequest $request, GenerateAgentPaymentAction $action): RedirectResponse
    {
        Gate::authorize('create', AgentPayment::class);

        $agent = Agent::findOrFail((int) $request->validated('agent_id'));
        Gate::authorize('pay', $agent);

        $action->handle($request->validated());

        return back(fallback: route('agent-payments.index'))->with('success', 'تم تسجيل دفعة العمولة بنجاح');
    }

    /**
     * Sum of unpaid rebate (and invoice count) per agent, within scope.
     *
     * @return array<int, array{rebate: float, count: int}>
     */
    private function outstandingByAgent(?int $branchId): array
    {
        $outstanding = [];

        $add = function (int $agentId, float $rebate, int $count) use (&$outstanding) {
            $outstanding[$agentId]['rebate'] = ($outstanding[$agentId]['rebate'] ?? 0) + $rebate;
            $outstanding[$agentId]['count'] = ($outstanding[$agentId]['count'] ?? 0) + $count;
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
            ->groupBy('agent_id')
            ->selectRaw('agent_id, SUM(agent_rebate) as rebate, COUNT(*) as cnt')
            ->get()
            ->each(fn ($row) => $add((int) $row->agent_id, (float) $row->rebate, (int) $row->cnt));

        // Service invoices settle each agent independently via the pivot.
        ServiceInvoiceAgent::query()
            ->whereNull('agent_payment_id')
            ->where('rebate_amount', '>', 0)
            ->whereHas('invoice', fn ($q) => $q
                ->where('status', InvoiceStatusEnum::PAID->value)
                ->when($branchId, fn ($qq) => $qq->where('branch_id', $branchId)))
            ->groupBy('agent_id')
            ->selectRaw('agent_id, SUM(rebate_amount) as rebate, COUNT(*) as cnt')
            ->get()
            ->each(fn ($row) => $add((int) $row->agent_id, (float) $row->rebate, (int) $row->cnt));

        return $outstanding;
    }
}
