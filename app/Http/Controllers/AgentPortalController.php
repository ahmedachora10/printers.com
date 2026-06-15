<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatusEnum;
use App\Models\AgentPayment;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
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

        foreach ([ProductInvoice::class => 'product', ServiceInvoice::class => 'service'] as $model => $type) {
            $base = $model::query()
                ->where('agent_id', $agent->id)
                ->where('status', '!=', InvoiceStatusEnum::CANCELLED->value);

            $row = (clone $base)
                ->selectRaw('COUNT(*) as cnt')
                ->selectRaw('COALESCE(SUM(agent_rebate), 0) as rebate')
                ->selectRaw('COALESCE(SUM(agent_discount), 0) as discount')
                ->selectRaw('COALESCE(SUM(CASE WHEN agent_payment_id IS NOT NULL THEN agent_rebate ELSE 0 END), 0) as paid')
                ->first();

            $summary['invoiceCount'] += (int) $row->cnt;
            $summary['rebateEarned'] += (float) $row->rebate;
            $summary['rebatePaid'] += (float) $row->paid;
            $summary['discountGiven'] += (float) $row->discount;

            (clone $base)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'invoice_number', 'total_amount', 'agent_rebate', 'agent_discount', 'agent_payment_id', 'status', 'created_at'])
                ->each(fn ($r) => $invoices->push([
                    'type' => $type,
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
        }

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
                'rate' => (float) ($agent->agentProfile?->rate ?? 0),
            ],
            'summary' => $summary,
            'recentInvoices' => $recentInvoices,
            'payments' => $payments,
        ]);
    }
}
