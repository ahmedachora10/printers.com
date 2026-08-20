<?php

namespace App\Http\Controllers;

use App\Actions\Agent\ListBranchAgentsAction;
use App\Actions\ProductInvoice\CreateProductInvoiceAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Http\Requests\ProductInvoice\StoreProductInvoiceRequest;
use App\Models\Branch;
use App\Models\LoyaltyConfig;
use App\Models\Product;
use App\Models\ProductInvoice;
use App\Notifications\DueInvoiceNotification;
use App\Support\BranchNotifiables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class ProductInvoiceController extends Controller
{
    public function create(ListBranchAgentsAction $listBranchAgents): Response
    {
        Gate::authorize('create', ProductInvoice::class);

        $branchId = Auth::user()->branchId;
        $branch = Branch::find($branchId);

        $products = Product::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'selling_price', 'current_stock', 'unit_id', 'is_sqm'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'sellingPrice' => (float) $product->selling_price,
                'currentStock' => (float) $product->current_stock,
                'unitName' => $product->unit?->name,
                // منتج بالمتر المربع: السعر أعلاه سعرُ المتر، والكاشير يُدخل المقاس.
                'isSqm' => (bool) $product->is_sqm,
            ]);

        $loyalty = $branchId ? LoyaltyConfig::forBranch($branchId) : null;
        $loyaltyActive = (bool) ($loyalty?->is_active);

        $agents = $listBranchAgents->handle($branchId);

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

        $invoice->load(['lines', 'customer:id,full_name,phone,tax_number', 'paymentMethod:id,name', 'branch:id,name,phone,address,tax_number']);

        // قبل الاعتماد الورقة عرض سعر لا فاتورة ضريبية — فلا تحمل الرقم الضريبي للفرع.
        $isQuotation = $invoice->status !== InvoiceStatusEnum::PAID;

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
                'customerTaxNumber' => $invoice->customer?->tax_number,
                'paymentMethod' => $invoice->paymentMethod?->name,
                'notes' => $invoice->notes,
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
                'taxNumber' => $isQuotation ? null : $invoice->branch?->tax_number,
                'logoUrl' => $invoice->branch?->getFirstMediaUrl('logo') ?: null,
            ],
        ]);
    }
}
