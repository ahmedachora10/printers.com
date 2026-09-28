<?php

namespace App\Http\Controllers;

use App\Actions\InvoiceReturnRequest\ApproveInvoiceReturnRequestAction;
use App\Actions\InvoiceReturnRequest\RejectInvoiceReturnRequestAction;
use App\Enums\ReturnRequestStatusEnum;
use App\Http\Requests\InvoiceReturnRequest\ApproveInvoiceReturnRequestRequest;
use App\Http\Requests\InvoiceReturnRequest\RejectInvoiceReturnRequestRequest;
use App\Http\Resources\InvoiceReturnRequest\InvoiceReturnRequestResource;
use App\Models\Branch;
use App\Models\InvoiceReturnRequest;
use App\Models\ServiceInvoice;
use App\Notifications\ReturnRequestNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تاسك 135 — طلبات استرجاع الموظفين: تبويب في «المرتجعات». المحاسب ومدير
 * الفرع والمدير العام يعتمدون (باختيار طريقة الردّ) أو يرفضون؛ المراجع يطّلع.
 */
class InvoiceReturnRequestController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewReviewQueue', ServiceInvoice::class);

        $user = Auth::user();
        $branchId = $user->roleName->isSuperAdmin() ? null : $user->branchId;
        $status = $request->input('status', ReturnRequestStatusEnum::PENDING->value);

        $requests = InvoiceReturnRequest::query()
            ->with(['invoice', 'requester:id,name', 'decider:id,name', 'refund.paymentMethod:id,name'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // طرق الردّ لكل فرعٍ في الصفحة — تُختار في نافذة الاعتماد.
        $paymentMethods = Branch::query()
            ->whereIn('id', $requests->getCollection()->pluck('branch_id')->unique())
            ->get()
            ->mapWithKeys(fn (Branch $b) => [
                $b->id => $b->enabledPaymentMethods()->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            ]);

        return Inertia::render('refunds/requests', [
            'items' => InvoiceReturnRequestResource::collection($requests),
            'paymentMethods' => $paymentMethods,
            'statuses' => array_map(
                fn (ReturnRequestStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                ReturnRequestStatusEnum::cases(),
            ),
            'filters' => ['status' => $status],
        ]);
    }

    public function approve(
        ApproveInvoiceReturnRequestRequest $request,
        InvoiceReturnRequest $returnRequest,
        ApproveInvoiceReturnRequestAction $action,
    ): RedirectResponse {
        Gate::authorize('updateStatus', $returnRequest->invoice);

        $action->handle($returnRequest, Auth::user(), $request->validated('payment_method_id'));

        return $this->decided($returnRequest->refresh(), 'تم اعتماد الطلب واسترجاع الفاتورة');
    }

    public function reject(
        RejectInvoiceReturnRequestRequest $request,
        InvoiceReturnRequest $returnRequest,
        RejectInvoiceReturnRequestAction $action,
    ): RedirectResponse {
        Gate::authorize('updateStatus', $returnRequest->invoice);

        $action->handle($returnRequest, Auth::user(), $request->validated('rejection_reason'));

        return $this->decided($returnRequest->refresh(), 'تم رفض طلب الاسترجاع');
    }

    private function decided(InvoiceReturnRequest $returnRequest, string $message): RedirectResponse
    {
        $returnRequest->requester?->notify(
            new ReturnRequestNotification($returnRequest, $returnRequest->invoice->invoice_number),
        );

        return back(fallback: route('refunds.requests.index'))->with('success', $message);
    }
}
