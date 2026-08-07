<?php

namespace App\Http\Controllers;

use App\Actions\Report\ResolveReportScope;
use App\Enums\InvoiceStatusEnum;
use App\Models\AgentPayment;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoiceAgent;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

        $summary = [
            'invoiceCount' => 0,
            'rebateEarned' => 0.0,
            'rebatePaid' => 0.0,
            'discountGiven' => 0.0,
        ];

        $invoices = collect();

        // Product invoices carry a single agent on the invoice row. Only approved
        // (paid) invoices are visible to the agent — a rebate is earned once the
        // accountant settles the invoice, never while it is still due.
        $productBase = ProductInvoice::query()
            ->where('agent_id', $agent->id)
            ->where('status', InvoiceStatusEnum::PAID->value)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$from, $to]);

        $productRow = (clone $productBase)
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(agent_rebate), 0) as rebate')
            ->selectRaw('COALESCE(SUM(agent_discount), 0) as discount')
            ->selectRaw('COALESCE(SUM(CASE WHEN agent_payment_id IS NOT NULL THEN agent_rebate ELSE 0 END), 0) as paid')
            ->first();

        $summary['invoiceCount'] += (int) $productRow->cnt;
        $summary['rebateEarned'] += (float) $productRow->rebate;
        $summary['rebatePaid'] += (float) $productRow->paid;
        $summary['discountGiven'] += (float) $productRow->discount;

        (clone $productBase)
            ->with(['user:id,name', 'branch:id,name', 'lines:id,invoice_id,product_name'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'invoice_number', 'user_id', 'branch_id', 'total_amount', 'agent_rebate', 'agent_discount', 'agent_payment_id', 'status', 'created_at'])
            ->each(fn ($r) => $invoices->push([
                'type' => 'product',
                'invoiceNumber' => $r->invoice_number,
                'branchName' => $r->branch?->name,
                'employeeName' => $r->user?->name,
                'itemsLabel' => $this->describeLines($r->lines, 'product_name'),
                'totalAmount' => (float) $r->total_amount,
                'rebate' => (float) $r->agent_rebate,
                'lineCommission' => 0.0,
                'discount' => (float) $r->agent_discount,
                'isRebatePaid' => $r->agent_payment_id !== null,
                'status' => $r->status->value,
                'statusLabel' => $r->status->label(),
                'createdAtRaw' => $r->created_at,
                'createdAt' => $r->created_at?->format('d/m/Y'),
            ]));

        // Service invoices settle each agent independently via the pivot; the
        // invoice may carry several agents but only this one's share is theirs.
        // As with product invoices, a share surfaces only once the invoice is paid.
        $serviceBase = ServiceInvoiceAgent::query()
            ->where('agent_id', $agent->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', InvoiceStatusEnum::PAID->value)
                ->when($branchId, fn ($qq) => $qq->where('branch_id', $branchId))
                ->whereBetween('created_at', [$from, $to]));

        // Earned = invoice-level rebate plus per-line commissions; both are
        // settled together by the same agent_payment_id stamp.
        $serviceRow = (clone $serviceBase)
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(rebate_amount + line_commission_amount), 0) as rebate')
            ->selectRaw('COALESCE(SUM(discount_amount), 0) as discount')
            ->selectRaw('COALESCE(SUM(CASE WHEN agent_payment_id IS NOT NULL THEN rebate_amount + line_commission_amount ELSE 0 END), 0) as paid')
            ->first();

        $summary['invoiceCount'] += (int) $serviceRow->cnt;
        $summary['rebateEarned'] += (float) $serviceRow->rebate;
        $summary['rebatePaid'] += (float) $serviceRow->paid;
        $summary['discountGiven'] += (float) $serviceRow->discount;

        (clone $serviceBase)
            ->with([
                'invoice:id,invoice_number,user_id,branch_id,total_amount,status,created_at',
                'invoice.user:id,name',
                'invoice.branch:id,name',
                'invoice.lines:id,invoice_id,service_name',
            ])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->each(fn (ServiceInvoiceAgent $r) => $invoices->push([
                'type' => 'service',
                'invoiceNumber' => $r->invoice?->invoice_number,
                'branchName' => $r->invoice?->branch?->name,
                'employeeName' => $r->invoice?->user?->name,
                'itemsLabel' => $this->describeLines($r->invoice?->lines, 'service_name'),
                'totalAmount' => (float) ($r->invoice?->total_amount ?? 0),
                'rebate' => (float) $r->rebate_amount,
                'lineCommission' => (float) $r->line_commission_amount,
                'discount' => (float) $r->discount_amount,
                'isRebatePaid' => $r->agent_payment_id !== null,
                'status' => $r->invoice?->status->value,
                'statusLabel' => $r->invoice?->status->label(),
                'createdAtRaw' => $r->invoice?->created_at,
                'createdAt' => $r->invoice?->created_at?->format('d/m/Y'),
            ]));

        $summary['rebateEarned'] = round($summary['rebateEarned'], 2);
        $summary['rebatePaid'] = round($summary['rebatePaid'], 2);
        $summary['discountGiven'] = round($summary['discountGiven'], 2);
        $summary['rebateOutstanding'] = round($summary['rebateEarned'] - $summary['rebatePaid'], 2);

        $recentInvoices = $invoices
            ->sortByDesc('createdAtRaw')
            ->take(20)
            ->map(fn ($i) => collect($i)->except('createdAtRaw')->all())
            ->values();

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

    /**
     * Name the invoice's work in one cell: the first line, plus a count of the
     * rest when there are more.
     *
     * @param  EloquentCollection<int, covariant \Illuminate\Database\Eloquent\Model>|null  $lines
     */
    private function describeLines(?EloquentCollection $lines, string $column): ?string
    {
        if ($lines === null || $lines->isEmpty()) {
            return null;
        }

        $first = $lines->first()->{$column};
        $rest = $lines->count() - 1;

        return $rest > 0 ? "{$first} و{$rest} أخرى" : $first;
    }
}
