<?php

namespace App\Http\Controllers;

use App\Actions\Refund\CreateRefundAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Http\Requests\Refund\StoreRefundRequest;
use App\Http\Resources\Refund\RefundResource;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RefundController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Refund::class);

        $branchId = Auth::user()->roleName->isSuperAdmin() ? null : Auth::user()->branchId;

        $refunds = Refund::query()
            ->with(['invoice', 'user:id,name'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('source_type'), fn ($q) => $q->where('source_type', $request->input('source_type')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('refunds/index', [
            'items' => RefundResource::collection($refunds),
            'sourceTypes' => array_map(
                fn (InvoiceTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                InvoiceTypeEnum::cases(),
            ),
            'filters' => $request->only(['source_type', 'date_from', 'date_to']),
        ]);
    }

    public function store(StoreRefundRequest $request, CreateRefundAction $action): RedirectResponse
    {
        Gate::authorize('create', Refund::class);

        $action->handle($request->validated(), $request->user());

        return to_route('refunds.index')->with('success', 'تم تسجيل المرتجع بنجاح');
    }

    /**
     * Resolve an invoice by its number for the refund form, returning a summary
     * (total, already-refunded, remaining refundable) as JSON.
     */
    public function lookup(Request $request): JsonResponse
    {
        Gate::authorize('create', Refund::class);

        $request->validate(['number' => ['required', 'string']]);

        $number = trim($request->string('number'));
        $branchId = Auth::user()->roleName->isSuperAdmin() ? null : Auth::user()->branchId;

        foreach (InvoiceTypeEnum::cases() as $type) {
            $invoice = $type->modelClass()::query()
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->where('invoice_number', $number)
                ->with('customer:id,full_name')
                ->first();

            if (! $invoice) {
                continue;
            }

            if ($invoice->status === InvoiceStatusEnum::CANCELLED) {
                return response()->json(['found' => false, 'message' => 'الفاتورة ملغاة ولا يمكن إرجاعها.']);
            }

            $alreadyRefunded = (float) Refund::query()
                ->where('invoice_type', $type->modelClass())
                ->where('invoice_id', $invoice->id)
                ->sum('amount');

            $total = (float) $invoice->total_amount;
            $hasProducts = $type === InvoiceTypeEnum::PRODUCT
                && $invoice->lines()->whereNotNull('product_id')->exists();

            $stockReversed = $type === InvoiceTypeEnum::PRODUCT && Refund::query()
                ->where('invoice_type', $type->modelClass())
                ->where('invoice_id', $invoice->id)
                ->where('stock_reversed', true)
                ->exists();

            return response()->json([
                'found' => true,
                'invoice' => [
                    'id' => $invoice->id,
                    'number' => $invoice->invoice_number,
                    'type' => $type->value,
                    'typeLabel' => $type->label(),
                    'status' => $invoice->status->value,
                    'totalAmount' => $total,
                    'alreadyRefunded' => round($alreadyRefunded, 2),
                    'refundable' => round($total - $alreadyRefunded, 2),
                    'customerName' => $invoice->customer?->full_name,
                    'hasProducts' => $hasProducts,
                    'stockReversed' => $stockReversed,
                ],
            ]);
        }

        return response()->json(['found' => false, 'message' => 'لم يتم العثور على فاتورة بهذا الرقم.']);
    }
}
