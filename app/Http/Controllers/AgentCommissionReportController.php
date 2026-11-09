<?php

namespace App\Http\Controllers;

use App\Actions\Report\ResolveReportScope;
use App\Enums\Roles;
use App\Exports\AgentCommissionReportExport;
use App\Http\Requests\Report\AgentCommissionReportFilterRequest;
use App\Models\Agent;
use App\Models\AgentCommissionEntry;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
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
 * (task 189 took the accountant out). The agent portal answers the same question for the agent;
 * this is the other side of that counter.
 *
 * تاسك 217: يُقرأ من دفتر المندوب (AgentCommissionEntry) كما تقرؤه دفعة المندوب:
 * كل مبلغ في شهر تحصيله — فاتورة أكتوبر المسدَّدة في نوفمبر في نوفمبر، والجزئية بقدر
 * كل دفعة — والمبيعات والخصم معه بالنسبة نفسها. القيود المختومة هي المصروف.
 */
class AgentCommissionReportController extends Controller
{
    public function index(AgentCommissionReportFilterRequest $request, ResolveReportScope $resolveScope): Response
    {
        $scope = $this->scope($request, $resolveScope);
        $perInvoice = AgentCommissionEntry::perInvoice($this->entries($scope));
        $rows = $this->agentRows($perInvoice);

        return Inertia::render('reports/agent-commissions/index', [
            'rows' => $rows,
            'lines' => $this->detailLines($perInvoice),
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
                'user' => $scope['userId'] ? (string) $scope['userId'] : null,
                'branch' => $scope['isSuper'] && $scope['branchId'] ? (string) $scope['branchId'] : null,
            ],
            'defaultDate' => now()->toDateString(),
            'agents' => $this->agentOptions($scope),
            'employees' => User::invoiceRaiserOptions($scope['branchId']),
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
            new AgentCommissionReportExport($this->agentRows(AgentCommissionEntry::perInvoice($this->entries($scope)))),
            'agent-commissions-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    /**
     * Report scope: the shared branch/date resolution plus the agent and
     * employee pickers. Employees have no business here — this is other people's money.
     *
     * The employee is the invoice's creator (تاسك 179). One from another branch
     * needs no check: the branch condition already leaves nothing to match.
     *
     * @return array{isSuper: bool, branchId: ?int, agentId: ?int, userId: ?int, from: Carbon, to: Carbon}
     */
    private function scope(AgentCommissionReportFilterRequest $request, ResolveReportScope $resolveScope): array
    {
        $role = $request->user()->roleName;

        abort_if($role === Roles::AGENT || $role?->isEmployee(), 403);

        return [
            ...$resolveScope->handle($request),
            'agentId' => $request->filled('agent') ? (int) $request->input('agent') : null,
            'userId' => $request->filled('user') ? (int) $request->input('user') : null,
        ];
    }

    /**
     * One row per مندوب: their invoices' rows summed.
     *
     * @param  Collection<int, AgentCommissionEntry>  $perInvoice
     * @return Collection<int, array<string, mixed>>
     */
    private function agentRows(Collection $perInvoice): Collection
    {
        return $perInvoice->groupBy('agent_id')
            ->map(function (Collection $rows) {
                $sum = fn (string $column) => round((float) $rows->sum($column), 2);
                $due = round($sum('rebate') + $sum('line_commission'), 2);

                return [
                    'agentId' => (int) $rows->first()->agent_id,
                    'agentName' => $rows->first()->agent?->name,
                    'invoiceCount' => $rows->count(),
                    'sales' => $sum('sales'),
                    'discount' => $sum('discount'),
                    'rebate' => $sum('rebate'),
                    'lineCommission' => $sum('line_commission'),
                    'due' => $due,
                    'paid' => $sum('paid'),
                    'outstanding' => round($due - $sum('paid'), 2),
                ];
            })
            ->sortBy('agentName')
            ->values();
    }

    /**
     * قيود المندوب في نطاق التقرير. الموظف صاحب الفاتورة (تاسك 179) على الفاتورة نفسها.
     *
     * @param  array<string, mixed>  $scope
     * @return Builder<AgentCommissionEntry>
     */
    private function entries(array $scope): Builder
    {
        return AgentCommissionEntry::query()
            ->whereBetween('earned_at', [$scope['from'], $scope['to']])
            ->when($scope['branchId'], fn ($q) => $q->where('branch_id', $scope['branchId']))
            ->when($scope['agentId'], fn ($q) => $q->where('agent_id', $scope['agentId']))
            ->when($scope['userId'], fn ($q) => $q->whereHasMorph('invoice', [ServiceInvoice::class, ProductInvoice::class], fn ($q) => $q->where('user_id', $scope['userId'])));
    }

    /**
     * Invoice-level drill-down under each agent: which employee raised it, what
     * was sold, and the agent's share of it collected in the period.
     *
     * @param  Collection<int, AgentCommissionEntry>  $perInvoice
     * @return Collection<int, array<string, mixed>>
     */
    private function detailLines(Collection $perInvoice): Collection
    {
        return $perInvoice->map(fn (AgentCommissionEntry $row) => ['agentId' => (int) $row->agent_id, ...$row->invoiceRow()])->values();
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
