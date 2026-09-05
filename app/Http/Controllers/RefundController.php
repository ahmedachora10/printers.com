<?php

namespace App\Http\Controllers;

use App\Actions\Refund\CreateRefundAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Enums\StockMovementTypeEnum;
use App\Http\Requests\Refund\StoreRefundRequest;
use App\Http\Resources\Refund\RefundResource;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\StockMovement;
use App\Notifications\RefundProcessedNotification;
use App\Support\BranchNotifiables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
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
        // الصلاحية تُفحص على الفاتورة المقصودة لا على الشاشة: المحاسب ممنوع من
        // مرتجع فاتورة معتمدة (تاسك 42)، فيُردّ بـ 403 هنا لا بإخفاء الزر وحده.
        Gate::authorize('create', [Refund::class, $this->refundTarget($request)]);

        $refund = $action->handle($request->validated(), $request->user());

        $refund->loadMissing('invoice:id,invoice_number');

        Notification::send(
            BranchNotifiables::forBranch($refund->branch_id, ['branch-admin']),
            new RefundProcessedNotification($refund, $refund->invoice?->invoice_number),
        );

        return back(fallback: route('refunds.index'))->with('success', 'تم تسجيل المرتجع بنجاح');
    }

    /**
     * Resolve an invoice by its number for the refund form, returning a summary
     * (total, collected, already-refunded, remaining refundable) as JSON. An
     * invoice with nothing left to refund is reported as not found, with the
     * reason — so the form never offers an amount the action would reject.
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

            if ($invoice->status === InvoiceStatusEnum::RETURNED) {
                return response()->json(['found' => false, 'message' => 'الفاتورة مُرتجعة بالفعل.']);
            }

            $alreadyRefunded = (float) Refund::query()
                ->where('invoice_type', $type->modelClass())
                ->where('invoice_id', $invoice->id)
                ->sum('amount');

            $total = (float) $invoice->total_amount;

            // القابل للإرجاع يُقاس على ما حُصِّل لا على الإجمالي — نفس سقف
            // CreateRefundAction، حتى لا تعرض الشاشة مبلغاً يرفضه الخادم.
            $collected = $invoice->paidAmount();

            if (round($collected - $alreadyRefunded, 2) <= 0) {
                return response()->json([
                    'found' => false,
                    'message' => $collected <= 0
                        ? 'لم يُحصَّل من هذه الفاتورة شيء، فلا مبلغ يُردّ.'
                        : 'تم إرجاع كامل ما حُصِّل من هذه الفاتورة.',
                ]);
            }

            $hasProducts = $type === InvoiceTypeEnum::PRODUCT
                && $invoice->lines()->whereNotNull('product_id')->exists();

            // خاماتُ فاتورة الخدمة قابلةٌ للإرجاع متى كانت قد خُصمت فعلاً — أي
            // متى اعتُمدت الفاتورة وكتبت حركات صرفها.
            $hasMaterials = $type === InvoiceTypeEnum::SERVICE && StockMovement::query()
                ->where('reference_type', ServiceInvoice::class)
                ->where('reference_id', $invoice->id)
                ->where('type', StockMovementTypeEnum::SALE_OUT)
                ->exists();

            $stockReversed = Refund::query()
                ->where('invoice_type', $type->modelClass())
                ->where('invoice_id', $invoice->id)
                ->where('stock_reversed', true)
                ->exists();

            // أو أن خاماتها أُعيدت من مسار الاسترجاع قبل أن يمرّ أي مرتجع.
            if ($hasMaterials && ! $stockReversed) {
                $stockReversed = StockMovement::query()
                    ->where('reference_type', ServiceInvoice::class)
                    ->where('reference_id', $invoice->id)
                    ->where('type', StockMovementTypeEnum::RETURN_IN)
                    ->exists();
            }

            return response()->json([
                'found' => true,
                'invoice' => [
                    'id' => $invoice->id,
                    'number' => $invoice->invoice_number,
                    'type' => $type->value,
                    'typeLabel' => $type->label(),
                    'status' => $invoice->status->value,
                    'totalAmount' => $total,
                    'collectedAmount' => round($collected, 2),
                    'alreadyRefunded' => round($alreadyRefunded, 2),
                    'refundable' => round($collected - $alreadyRefunded, 2),
                    'customerName' => $invoice->customer?->full_name,
                    'hasProducts' => $hasProducts,
                    'hasMaterials' => $hasMaterials,
                    'stockReversed' => $stockReversed,
                ],
            ]);
        }

        return response()->json(['found' => false, 'message' => 'لم يتم العثور على فاتورة بهذا الرقم.']);
    }

    /**
     * الفاتورة التي يُطلب المرتجع عليها، لتُفحص الصلاحية على حالتها. تُعاد null
     * إن لم تُعثر — عندها تتكفّل قواعد التحقق في الـ Action بالرفض.
     */
    private function refundTarget(StoreRefundRequest $request): ProductInvoice|ServiceInvoice|null
    {
        $type = InvoiceTypeEnum::tryFrom((string) $request->validated('source_type'));

        return $type?->modelClass()::find($request->validated('invoice_id'));
    }
}
