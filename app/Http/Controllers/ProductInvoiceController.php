<?php

namespace App\Http\Controllers;

use App\Actions\Agent\ListBranchAgentsAction;
use App\Actions\Loyalty\ResolveAvailablePointsAction;
use App\Actions\ProductInvoice\CreateProductInvoiceAction;
use App\Actions\ProductInvoice\UpdateProductInvoiceAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Http\Requests\ProductInvoice\StoreProductInvoiceRequest;
use App\Http\Requests\ProductInvoice\UpdateProductInvoiceRequest;
use App\Models\Branch;
use App\Models\Coupon;
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

        return Inertia::render('pos/product/index', $this->posFormData(Auth::user()->branchId, $listBranchAgents));
    }

    /**
     * شاشة نقطة البيع نفسها مفتوحةً على فاتورة قائمة. تُبنى من فرع **الفاتورة**
     * لا من فرع المعدِّل (مدير النظام بلا فرع)، وكميات الفاتورة نفسها تُضاف إلى
     * المخزون المتاح لها.
     */
    public function edit(ProductInvoice $invoice, ListBranchAgentsAction $listBranchAgents, ResolveAvailablePointsAction $availablePoints): Response
    {
        Gate::authorize('update', $invoice);

        $invoice->load(['lines', 'customer']);

        $branchId = (int) $invoice->branch_id;
        $heldQty = $invoice->lines->whereNotNull('product_id')->groupBy('product_id')
            ->map(fn ($lines) => round((float) $lines->sum('qty'), 2))
            ->all();

        $form = $this->posFormData($branchId, $listBranchAgents, $heldQty);
        $stock = $form['products']->pluck('currentStock', 'id');
        $loyalty = LoyaltyConfig::forBranch($branchId);
        $coupon = $invoice->coupon_id ? Coupon::find($invoice->coupon_id) : null;

        return Inertia::render('pos/product/index', [
            ...$form,
            // الضريبة المسجَّلة على الفاتورة، لا نسبة الفرع الحالية.
            'vatPct' => (float) $invoice->vat_pct,
            'invoice' => [
                'id' => $invoice->id,
                'invoiceNumber' => $invoice->invoice_number,
                'statusLabel' => $invoice->status->label(),
                // حجز الفاتورة نفسها لا يُطرح من رصيد عميلها، وما خُصم عليها فعلاً
                // يُضاف إليه: التعديل يردّه قبل أن يعيد الخصم.
                'customer' => $invoice->customer?->toPosArray(
                    $loyalty,
                    (bool) $loyalty->is_active,
                    $availablePoints->reserved((int) $invoice->customer_id, $invoice)
                        - ($invoice->points_redeemed_at !== null ? (int) $invoice->points_redeemed : 0),
                ),
                'agentId' => $invoice->agent_id,
                'coupon' => $coupon ? [
                    'code' => $coupon->code,
                    'type' => $coupon->discount_type->value,
                    'value' => (float) $coupon->discount_value,
                ] : null,
                'pointsRedeemed' => (int) $invoice->points_redeemed,
                'paymentMethodId' => $invoice->payment_method_id,
                'hasReceipt' => $invoice->hasReceipt(),
                'notes' => $invoice->notes,
                'internalNotes' => $invoice->internal_notes,
                'lines' => $invoice->lines->map(fn ($line) => [
                    'key' => "l-{$line->id}",
                    'productId' => $line->product_id,
                    'name' => $line->product_name,
                    'sku' => $line->sku ?? '',
                    'unitPrice' => (float) $line->unit_price,
                    'qty' => (float) $line->qty,
                    'discountPct' => (float) $line->discount_pct,
                    'maxStock' => $line->product_id ? ($stock[$line->product_id] ?? $heldQty[$line->product_id]) : null,
                    'unitName' => null,
                    'isManual' => false,
                    'isSqm' => $line->width_cm !== null,
                    'widthCm' => $line->width_cm !== null ? (float) $line->width_cm : null,
                    'heightCm' => $line->height_cm !== null ? (float) $line->height_cm : null,
                    'pieces' => (int) ($line->pieces ?? 1),
                ])->values(),
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

    public function update(UpdateProductInvoiceRequest $request, ProductInvoice $invoice, UpdateProductInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('update', $invoice);

        $action->handle($invoice, $request->validated(), $request->user(), $request->file('receipt'));

        $redirect = $request->boolean('print')
            ? to_route('pos.product.print', $invoice)
            : to_route('invoices.show', ['type' => InvoiceTypeEnum::PRODUCT->value, 'id' => $invoice->id]);

        return $redirect->with('success', "تم تحديث الفاتورة {$invoice->invoice_number} بنجاح");
    }

    public function print(ProductInvoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $invoice->load(['lines', 'user:id,name', 'customer:id,full_name,phone,tax_number', 'paymentMethod:id,name', 'branch:id,name,phone,address,tax_number']);

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
                // الموظف صاحب الفاتورة — مَن أنشأها لا مَن يطبعها.
                'userName' => $invoice->user?->name,
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

    /**
     * ما تحتاجه شاشة نقطة البيع من فرعٍ ما. `$heldQty` كميات الفاتورة المعدَّلة
     * لكل منتج، تُضاف إلى مخزونه المتاح لها.
     *
     * @param  array<int, float>  $heldQty
     * @return array<string, mixed>
     */
    private function posFormData(?int $branchId, ListBranchAgentsAction $listBranchAgents, array $heldQty = []): array
    {
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
                'currentStock' => round((float) $product->current_stock + ($heldQty[$product->id] ?? 0), 2),
                'unitName' => $product->unit?->name,
                // منتج بالمتر المربع: السعر أعلاه سعرُ المتر، والكاشير يُدخل المقاس.
                'isSqm' => (bool) $product->is_sqm,
            ]);

        $loyalty = $branchId ? LoyaltyConfig::forBranch($branchId) : null;

        $paymentMethods = $branch
            ? $branch->enabledPaymentMethods()->map(fn ($method) => [
                'id' => $method->id,
                'name' => $method->name,
                'requiresAttachment' => (bool) $method->requires_attachment,
            ])->values()
            : collect();

        return [
            'products' => $products,
            'agents' => $listBranchAgents->handle($branchId),
            'paymentMethods' => $paymentMethods,
            'vatPct' => (float) ($branch->vat_rate_override ?? 15),
            'loyalty' => [
                'active' => (bool) ($loyalty?->is_active),
                'redemptionRate' => (float) ($loyalty?->redemption_rate ?? 0),
                'minRedemptionPoints' => (int) ($loyalty?->min_redemption_points ?? 0),
            ],
        ];
    }
}
