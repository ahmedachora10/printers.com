<?php

namespace App\Http\Controllers;

use App\Actions\ProductInvoice\CreateProductInvoiceAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Http\Requests\ProductInvoice\StoreProductInvoiceRequest;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\LoyaltyConfig;
use App\Models\Product;
use App\Models\ProductInvoice;
use App\Notifications\DueInvoiceNotification;
use App\Support\BranchNotifiables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class ProductInvoiceController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('create', ProductInvoice::class);

        $branchId = Auth::user()->branchId;
        $branch = Branch::find($branchId);

        $products = Product::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'selling_price', 'current_stock', 'unit_id'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'sellingPrice' => (float) $product->selling_price,
                'currentStock' => $product->current_stock,
                'unitName' => $product->unit?->name,
            ]);

        $loyalty = $branchId ? LoyaltyConfig::forBranch($branchId) : null;
        $loyaltyActive = (bool) ($loyalty?->is_active);

        $agents = $this->branchAgents($branchId);

        $paymentMethods = $branch
            ? $branch->enabledPaymentMethods()->map(fn ($method) => [
                'id' => $method->id,
                'name' => $method->name,
                'requiresAttachment' => (bool) $method->requires_attachment,
            ])->values()
            : collect();

        return Inertia::render('pos/product/index', [
            'products' => $products,
            'agents' => $agents,
            'paymentMethods' => $paymentMethods,
            'vatPct' => (float) ($branch->vat_rate_override ?? 15),
            'loyalty' => [
                'active' => $loyaltyActive,
                'redemptionRate' => (float) ($loyalty?->redemption_rate ?? 0),
                'minRedemptionPoints' => (int) ($loyalty?->min_redemption_points ?? 0),
            ],
        ]);
    }

    public function store(StoreProductInvoiceRequest $request, CreateProductInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('create', ProductInvoice::class);

        $invoice = $action->handle($request->validated(), $request->file('receipt'));

        if ($invoice->status === InvoiceStatusEnum::DUE) {
            Notification::send(
                BranchNotifiables::forBranch($invoice->branch_id, ['branch-admin', 'accountant']),
                new DueInvoiceNotification($invoice->invoice_number, $invoice->id, InvoiceTypeEnum::PRODUCT, (float) $invoice->total_amount),
            );
        }

        if ($request->boolean('print')) {
            return to_route('pos.product.print', $invoice)
                ->with('success', "تم حفظ الفاتورة {$invoice->invoice_number} بنجاح");
        }

        return to_route('pos.product.create')
            ->with('success', "تم حفظ الفاتورة {$invoice->invoice_number} بنجاح");
    }

    public function print(ProductInvoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $invoice->load(['lines', 'customer:id,full_name,phone', 'paymentMethod:id,name', 'branch:id,name,phone,address,tax_number']);

        return Inertia::render('pos/product/print', [
            'invoice' => [
                'invoiceNumber' => $invoice->invoice_number,
                'createdAt' => $invoice->created_at?->toIso8601String(),
                'status' => $invoice->status->value,
                'statusLabel' => $invoice->status->label(),
                'subtotal' => (float) $invoice->subtotal,
                'tierDiscountAmount' => (float) $invoice->tier_discount_amount,
                'couponDiscount' => (float) $invoice->coupon_discount,
                'agentDiscount' => (float) $invoice->agent_discount,
                'pointsDiscount' => (float) $invoice->points_discount,
                'vatPct' => (float) $invoice->vat_pct,
                'vatAmount' => (float) $invoice->vat_amount,
                'totalAmount' => (float) $invoice->total_amount,
                'customerName' => $invoice->customer?->full_name,
                'customerPhone' => $invoice->customer?->phone,
                'paymentMethod' => $invoice->paymentMethod?->name,
                'lines' => $invoice->lines->map(fn ($line) => [
                    'name' => $line->product_name,
                    'sku' => $line->sku,
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
                'discountType' => $agent->agentProfile?->discount_type?->value ?? 'percentage',
                'rate' => (float) ($agent->agentProfile?->rate ?? 0),
            ])
            ->values();
    }
}
