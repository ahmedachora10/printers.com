<?php

namespace App\Http\Controllers;

use App\Actions\Report\ResolveReportScope;
use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Exports\AgentCommissionReportExport;
use App\Http\Requests\Report\AgentCommissionReportFilterRequest;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoiceAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * What each مندوب earned, for the people who owe it to them — the branch admin
 * and the accountant. The agent portal answers the same question for the agent;
 * this is the other side of that counter.
 *
 * Money is read exactly where the payment run reads it (GenerateAgentPaymentAction):
 * approved invoices only, dated by created_at, with the agent_payment_id stamp
 * marking what has already been settled. Both invoice kinds count — a service
 * invoice through its pivot (several agents may share one), a product invoice
 * through its single agent column — so the totals agree with the portal.
 */
class AgentCommissionReportController extends Controller
{
    public function index(AgentCommissionReportFilterRequest $request, ResolveReportScope $resolveScope): Response
    {
        $scope = $this->scope($request, $resolveScope);
        $rows = $this->agentRows($scope);

        return Inertia::render('reports/agent-commissions/index', [
            'rows' => $rows,
            'lines' => $this->detailLines($scope),
            'totals' => [
                'agentCount' => $rows->count(),
                'invoiceCount' => (int) $rows->sum('invoiceCount'),
                'sales' => round((float) $rows->sum('sales'), 2),
                'discount' => round((float) $rows->sum('discount'), 2),
                'rebate' => round((float) $rows->sum('rebate'), 2),
                'lineCommission' => round((float) $rows->sum('lineCommission'), 2),
                'due' => round((float) $rows->sum('due'), 2),
                'paid' => round((float) $rows->sum('paid'), 2),
                'outstanding' => round((float) $rows->sum('outstanding'), 2),
            ],
            'filters' => [
                'from' => $scope['from']->toDateString(),
                'to' => $scope['to']->toDateString(),
                'agent' => $scope['agentId'] ? (string) $scope['agentId'] : null,
                'branch' => $scope['isSuper'] && $scope['branchId'] ? (string) $scope['branchId'] : null,
            ],
            'defaultDate' => now()->toDateString(),
            'agents' => $this->agentOptions($scope),
            'branches' => $scope['isSuper']
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'isSuperAdmin' => $scope['isSuper'],
        ]);
    }

    public function export(AgentCommissionReportFilterRequest $request, ResolveReportScope $resolveScope): BinaryFileResponse|HttpResponse
    {
        $scope = $this->scope($request, $resolveScope);

        return Excel::download(
            new AgentCommissionReportExport($this->agentRows($scope)),
            'agent-commissions-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    /**
     * Report scope: the shared branch/date resolution plus the agent picker.
     * Employees have no business here — this is other people's money.
     *
     * @return array{isSuper: bool, branchId: ?int, agentId: ?int, from: Carbon, to: Carbon}
     */
    private function scope(AgentCommissionReportFilterRequest $request, ResolveReportScope $resolveScope): array
    {
        $role = $request->user()->roleName;

        abort_if($role === Roles::AGENT || $role?->isEmployee(), 403);

        return [
            ...$resolveScope->handle($request),
            'agentId' => $request->filled('agent') ? (int) $request->input('agent') : null,
        ];
    }

    /**
     * One row per مندوب, merging their service and product earnings.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function agentRows(array $scope): Collection
    {
        $rows = [];

        foreach ([$this->serviceTotals($scope), $this->productTotals($scope)] as $source) {
            foreach ($source as $row) {
                $id = (int) $row->agent_id;

                $rows[$id] ??= [
                    'agentId' => $id,
                    'agentName' => $row->agent_name,
                    'invoiceCount' => 0,
                    'sales' => 0.0,
                    'discount' => 0.0,
                    'rebate' => 0.0,
                    'lineCommission' => 0.0,
                    'paid' => 0.0,
                ];

                $rows[$id]['invoiceCount'] += (int) $row->invoice_count;
                $rows[$id]['sales'] += (float) $row->sales;
                $rows[$id]['discount'] += (float) $row->discount;
                $rows[$id]['rebate'] += (float) $row->rebate;
                $rows[$id]['lineCommission'] += (float) $row->line_commission;
                $rows[$id]['paid'] += (float) $row->paid;
            }
        }

        return collect($rows)
            ->map(function (array $row) {
                $due = round($row['rebate'] + $row['lineCommission'], 2);

                return [
                    ...$row,
                    'sales' => round($row['sales'], 2),
                    'discount' => round($row['discount'], 2),
                    'rebate' => round($row['rebate'], 2),
                    'lineCommission' => round($row['lineCommission'], 2),
                    'due' => $due,
                    'paid' => round($row['paid'], 2),
                    'outstanding' => round($due - $row['paid'], 2),
                ];
            })
            ->sortBy('agentName')
            ->values();
    }

    /**
     * Service-invoice earnings per agent, off the pivot.
     *
     * `sales` is the whole invoice total, counted once per agent on it: when two
     * agents share an invoice each is credited with the sale they were part of,
     * so the sales column is per-agent volume and not a branch revenue figure.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, object>
     */
    private function serviceTotals(array $scope): Collection
    {
        return $this->serviceQuery($scope)
            ->join('users', 'users.id', '=', 'service_invoice_agent.agent_id')
            ->groupBy('service_invoice_agent.agent_id', 'users.name')
            ->selectRaw('service_invoice_agent.agent_id as agent_id')
            ->selectRaw('users.name as agent_name')
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(service_invoices.total_amount), 0) as sales')
            ->selectRaw('COALESCE(SUM(service_invoice_agent.discount_amount), 0) as discount')
            ->selectRaw('COALESCE(SUM(service_invoice_agent.rebate_amount), 0) as rebate')
            ->selectRaw('COALESCE(SUM(service_invoice_agent.line_commission_amount), 0) as line_commission')
            ->selectRaw('COALESCE(SUM(CASE WHEN service_invoice_agent.agent_payment_id IS NOT NULL
                THEN service_invoice_agent.rebate_amount + service_invoice_agent.line_commission_amount ELSE 0 END), 0) as paid')
            ->get();
    }

    /**
     * Product-invoice earnings per agent, off the invoice's own agent columns.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, object>
     */
    private function productTotals(array $scope): Collection
    {
        return $this->productQuery($scope)
            ->join('users', 'users.id', '=', 'product_invoices.agent_id')
            ->groupBy('product_invoices.agent_id', 'users.name')
            ->selectRaw('product_invoices.agent_id as agent_id')
            ->selectRaw('users.name as agent_name')
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('COALESCE(SUM(product_invoices.total_amount), 0) as sales')
            ->selectRaw('COALESCE(SUM(product_invoices.agent_discount), 0) as discount')
            ->selectRaw('COALESCE(SUM(product_invoices.agent_rebate), 0) as rebate')
            ->selectRaw('0 as line_commission')
            ->selectRaw('COALESCE(SUM(CASE WHEN product_invoices.agent_payment_id IS NOT NULL
                THEN product_invoices.agent_rebate ELSE 0 END), 0) as paid')
            ->get();
    }

    /** @param array<string, mixed> $scope */
    private function serviceQuery(array $scope): Builder
    {
        return ServiceInvoiceAgent::query()
            ->join('service_invoices', 'service_invoices.id', '=', 'service_invoice_agent.service_invoice_id')
            ->whereNull('service_invoices.deleted_at')
            ->where('service_invoices.status', InvoiceStatusEnum::PAID->value)
            ->whereBetween('service_invoices.created_at', [$scope['from'], $scope['to']])
            ->when($scope['branchId'], fn ($q) => $q->where('service_invoices.branch_id', $scope['branchId']))
            ->when($scope['agentId'], fn ($q) => $q->where('service_invoice_agent.agent_id', $scope['agentId']));
    }

    /** @param array<string, mixed> $scope */
    private function productQuery(array $scope): Builder
    {
        return ProductInvoice::query()
            ->whereNotNull('product_invoices.agent_id')
            ->where('product_invoices.status', InvoiceStatusEnum::PAID->value)
            ->whereBetween('product_invoices.created_at', [$scope['from'], $scope['to']])
            ->when($scope['branchId'], fn ($q) => $q->where('product_invoices.branch_id', $scope['branchId']))
            ->when($scope['agentId'], fn ($q) => $q->where('product_invoices.agent_id', $scope['agentId']));
    }

    /**
     * Invoice-level drill-down under each agent: which employee raised it, what
     * was sold, and the agent's share of it.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function detailLines(array $scope): Collection
    {
        $service = ServiceInvoiceAgent::query()
            ->whereIn('id', $this->serviceQuery($scope)->select('service_invoice_agent.id'))
            ->with(['invoice:id,invoice_number,user_id,total_amount,created_at', 'invoice.user:id,name', 'invoice.lines:id,invoice_id,service_name'])
            ->get()
            ->map(fn (ServiceInvoiceAgent $row) => [
                'agentId' => (int) $row->agent_id,
                'type' => 'service',
                'invoiceNumber' => $row->invoice?->invoice_number,
                'employeeName' => $row->invoice?->user?->name,
                'itemsLabel' => $this->describeLines($row->invoice?->lines, 'service_name'),
                'invoiceTotal' => (float) ($row->invoice?->total_amount ?? 0),
                'amount' => round((float) $row->rebate_amount + (float) $row->line_commission_amount, 2),
                'isPaid' => $row->agent_payment_id !== null,
                'date' => $row->invoice?->created_at?->toDateString(),
            ]);

        $product = $this->productQuery($scope)
            ->with(['user:id,name', 'lines:id,invoice_id,product_name'])
            ->get(['id', 'invoice_number', 'agent_id', 'user_id', 'total_amount', 'agent_rebate', 'agent_payment_id', 'created_at'])
            ->map(fn (ProductInvoice $row) => [
                'agentId' => (int) $row->agent_id,
                'type' => 'product',
                'invoiceNumber' => $row->invoice_number,
                'employeeName' => $row->user?->name,
                'itemsLabel' => $this->describeLines($row->lines, 'product_name'),
                'invoiceTotal' => (float) $row->total_amount,
                'amount' => round((float) $row->agent_rebate, 2),
                'isPaid' => $row->agent_payment_id !== null,
                'date' => $row->created_at?->toDateString(),
            ]);

        return $service->concat($product)->sortByDesc('date')->values();
    }

    /**
     * Name the invoice's work in one cell: the first line, plus a count of the rest.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, covariant \Illuminate\Database\Eloquent\Model>|null  $lines
     */
    private function describeLines(?\Illuminate\Database\Eloquent\Collection $lines, string $column): ?string
    {
        if ($lines === null || $lines->isEmpty()) {
            return null;
        }

        $first = $lines->first()->{$column};
        $rest = $lines->count() - 1;

        return $rest > 0 ? "{$first} و{$rest} أخرى" : $first;
    }

    /**
     * Agents selectable in the filter — those of the scoped branch.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array{id: int, name: string}>
     */
    private function agentOptions(array $scope): Collection
    {
        return Agent::query()
            // Agents are picked by the branches they work with, not by the single
            // primary branch_id column.
            ->when($scope['branchId'], fn ($q) => $q->forBranch($scope['branchId']))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Agent $agent) => ['id' => $agent->id, 'name' => $agent->name])
            ->values();
    }
}
