<?php

namespace App\Http\Controllers;

use App\Actions\ServiceInvoice\CreateServiceInvoiceAction;
use App\Http\Requests\ServiceInvoice\StoreServiceInvoiceRequest;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Customer;
use App\Models\ServiceInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ServiceInvoiceController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('create', ServiceInvoice::class);

        $branchId = Auth::user()->branchId;
        $branch = Branch::find($branchId);

        $services = BranchService::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with('serviceTemplate:id,name')
            ->get()
            ->map(fn (BranchService $service) => [
                'id' => $service->id,
                'name' => $service->serviceTemplate?->name,
                'baseCommissionPct' => (float) $service->base_commission_pct,
                'maxDiscountPct' => (float) $service->max_discount_pct,
                'isTahazir' => $service->is_tahazir,
            ])
            ->filter(fn ($service) => $service['name'] !== null)
            ->values();

        $customers = Customer::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'phone', 'agent_id'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'fullName' => $customer->full_name,
                'phone' => $customer->phone,
                'agentId' => $customer->agent_id,
            ]);

        $agents = $this->branchAgents($branchId);

        $paymentMethods = $branch
            ? $branch->enabledPaymentMethods()->map(fn ($method) => [
                'id' => $method->id,
                'name' => $method->name,
            ])->values()
            : collect();

        return Inertia::render('pos/service/index', [
            'services' => $services,
            'customers' => $customers,
            'agents' => $agents,
            'paymentMethods' => $paymentMethods,
            'vatPct' => (float) ($branch->vat_rate_override ?? 15),
        ]);
    }

    public function store(StoreServiceInvoiceRequest $request, CreateServiceInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('create', ServiceInvoice::class);

        $invoice = $action->handle($request->validated());

        if ($request->boolean('print')) {
            return to_route('pos.service.print', $invoice)
                ->with('success', "تم حفظ الفاتورة {$invoice->invoice_number} بنجاح");
        }

        return to_route('pos.service.create')
            ->with('success', "تم حفظ الفاتورة {$invoice->invoice_number} بنجاح");
    }

    public function print(ServiceInvoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $invoice->load(['lines', 'customer:id,full_name,phone', 'paymentMethod:id,name', 'branch:id,name,phone,address,tax_number']);

        return Inertia::render('pos/service/print', [
            'invoice' => [
                'invoiceNumber' => $invoice->invoice_number,
                'createdAt' => $invoice->created_at?->toIso8601String(),
                'status' => $invoice->status->value,
                'statusLabel' => $invoice->status->label(),
                'subtotal' => (float) $invoice->subtotal,
                'couponDiscount' => (float) $invoice->coupon_discount,
                'agentDiscount' => (float) $invoice->agent_discount,
                'vatPct' => (float) $invoice->vat_pct,
                'vatAmount' => (float) $invoice->vat_amount,
                'totalAmount' => (float) $invoice->total_amount,
                'customerName' => $invoice->customer?->full_name,
                'customerPhone' => $invoice->customer?->phone,
                'paymentMethod' => $invoice->paymentMethod?->name,
                'lines' => $invoice->lines->map(fn ($line) => [
                    'name' => $line->service_name,
                    'sku' => null,
                    'qty' => $line->qty,
                    'unitPrice' => (float) $line->unit_price,
                    'discountPct' => (float) $line->discount_pct,
                    'subtotal' => (float) $line->subtotal,
                ])->values(),
            ],
            'branch' => [
                'name' => $invoice->branch?->name,
                'phone' => $invoice->branch?->phone,
                'address' => $invoice->branch?->address,
                'taxNumber' => $invoice->branch?->tax_number,
            ],
        ]);
    }

    /**
     * Active agents for the branch, with the terms the POS previews.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function branchAgents(?int $branchId): Collection
    {
        return Agent::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with('agentProfile')
            ->orderBy('name')
            ->get()
            ->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'discountMode' => $agent->agentProfile?->discount_mode?->value,
                'rate' => (float) ($agent->agentProfile?->rate ?? 0),
            ])
            ->values();
    }
}
