<?php

namespace App\Http\Controllers;

use App\Actions\ProductInvoice\CreateProductInvoiceAction;
use App\Http\Requests\ProductInvoice\StoreProductInvoiceRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

        $customers = Customer::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'phone'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'fullName' => $customer->full_name,
                'phone' => $customer->phone,
            ]);

        $paymentMethods = $branch
            ? $branch->enabledPaymentMethods()->map(fn ($method) => [
                'id' => $method->id,
                'name' => $method->name,
            ])->values()
            : collect();

        return Inertia::render('pos/product/index', [
            'products' => $products,
            'customers' => $customers,
            'paymentMethods' => $paymentMethods,
            'vatPct' => (float) ($branch?->vat_rate_override ?? 15),
        ]);
    }

    public function store(StoreProductInvoiceRequest $request, CreateProductInvoiceAction $action): RedirectResponse
    {
        Gate::authorize('create', ProductInvoice::class);

        $invoice = $action->handle($request->validated());

        return to_route('pos.product.create')
            ->with('success', "تم حفظ الفاتورة {$invoice->invoice_number} بنجاح");
    }
}