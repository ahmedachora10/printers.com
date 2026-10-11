<?php

namespace App\Http\Controllers;

use App\Actions\Shipping\ApproveShipmentAction;
use App\Actions\Shipping\RequestShipmentAction;
use App\Http\Requests\ServiceInvoice\RequestShipmentRequest;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceShipment;
use App\Notifications\ShipmentRequestNotification;
use App\Support\BranchNotifiables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * توصيلٌ بعد اعتماد الفاتورة: يطلبه أيّ مستخدم في الفرع، ويعتمده المحاسب أو يرفضه.
 */
class ShipmentRequestController extends Controller
{
    public function store(RequestShipmentRequest $request, ServiceInvoice $invoice, RequestShipmentAction $action): RedirectResponse
    {
        Gate::authorize('requestShipment', $invoice);

        $shipment = $action->handle($invoice, $request->validated('shipments.0'), $request->user());

        Notification::send(
            BranchNotifiables::forBranch($invoice->branch_id, ['branch-admin', 'accountant'])
                ->reject(fn ($user) => $user->id === $request->user()->id),
            new ShipmentRequestNotification($shipment, ShipmentRequestNotification::SUBMITTED),
        );

        return back()->with('success', 'تم إرسال طلب التوصيل إلى المحاسب لاعتماده');
    }

    /** `{pendingShipment}` مقيَّدٌ بـ pendingShipments() للفاتورة: طلبٌ لغيرها أو معتمدٌ = 404. */
    public function approve(Request $request, ServiceInvoice $invoice, ServiceInvoiceShipment $pendingShipment, ApproveShipmentAction $action): RedirectResponse
    {
        Gate::authorize('approveShipment', $invoice);

        $action->handle($pendingShipment, $request->user());
        $this->notifyRequester($request, $pendingShipment, ShipmentRequestNotification::APPROVED);

        return back()->with('success', 'تم اعتماد التوصيل وإضافته إلى الفاتورة');
    }

    /** الرفض = حذف الطلب المنتظر، والسبب يصل من طلبه. */
    public function reject(Request $request, ServiceInvoice $invoice, ServiceInvoiceShipment $pendingShipment): RedirectResponse
    {
        Gate::authorize('approveShipment', $invoice);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        $pendingShipment->delete();
        $this->notifyRequester($request, $pendingShipment, ShipmentRequestNotification::REJECTED, $reason);

        return back()->with('success', 'تم رفض طلب التوصيل');
    }

    private function notifyRequester(Request $request, ServiceInvoiceShipment $shipment, string $event, ?string $reason = null): void
    {
        if ((int) $shipment->requested_by !== $request->user()->id) {
            $shipment->requestedBy?->notify(new ShipmentRequestNotification($shipment, $event, $reason));
        }
    }
}
