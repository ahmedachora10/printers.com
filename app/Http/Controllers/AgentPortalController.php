<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatusEnum;
use App\Models\AgentPayment;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoiceAgent;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AgentPortalController extends Controller
{
    public function index(): Response
    {
        $agent = Auth::user();
        $agent->loadMissing(['agentProfile', 'branch:id,name']);

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
            ->where('status', InvoiceStatusEnum::PAID->value);

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
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'invoice_number', 'total_amount', 'agent_rebate', 'agent_discount', 'agent_payment_id', 'status', 'created_at'])
            ->each(fn ($r) => $invoices->push([
                'type' => 'product',
                'invoiceNumber' => $r->invoice_number,
                'totalAmount' => (float) $r->total_amount,
                'rebate' => (float) $r->agent_rebate,
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
            ->whereHas('invoice', fn ($q) => $q->where('status', InvoiceStatusEnum::PAID->value));

        $serviceRow = (clone $serviceBase)
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(rebate_amount), 0) as rebate')
            ->selectRaw('COALESCE(SUM(discount_amount), 0) as discount')
            ->selectRaw('COALESCE(SUM(CASE WHEN agent_payment_id IS NOT NULL THEN rebate_amount ELSE 0 END), 0) as paid')
            ->first();

        $summary['invoiceCount'] += (int) $serviceRow->cnt;
        $summary['rebateEarned'] += (float) $serviceRow->rebate;
        $summary['rebatePaid'] += (float) $serviceRow->paid;
        $summary['discountGiven'] += (float) $serviceRow->discount;

        (clone $serviceBase)
            ->with('invoice:id,invoice_number,total_amount,status,created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->each(fn (ServiceInvoiceAgent $r) => $invoices->push([
                'type' => 'service',
                'invoiceNumber' => $r->invoice?->invoice_number,
                'totalAmount' => (float) ($r->invoice?->total_amount ?? 0),
                'rebate' => (float) $r->rebate_amount,
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
            ->orderByDesc('paid_at')
            ->limit(20)
            ->get()
            ->map(fn (AgentPayment $p) => [
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
                'branchName' => $agent->branch?->name,
                'discountMode' => $agent->agentProfile?->discount_mode?->value,
                'discountModeLabel' => $agent->agentProfile?->discount_mode?->label(),
                'discountType' => $agent->agentProfile?->discount_type?->value ?? 'percentage',
                'rate' => (float) ($agent->agentProfile?->rate ?? 0),
            ],
            'summary' => $summary,
            'recentInvoices' => $recentInvoices,
            'payments' => $payments,
        ]);
    }
}
