<?php

namespace App\Http\Controllers;

use App\Actions\Shipping\SettleDeliveryAction;
use App\Http\Requests\Shipping\SettleDeliveryRequest;
use App\Models\DeliveryProvider;
use App\Models\Expense;
use App\Models\ServiceInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** تاسك 111 — تسوية أجر السائق لكل طلب من كشف التوصيل، وإلغاؤها. */
class DeliverySettlementController extends Controller
{
    public function store(SettleDeliveryRequest $request, ServiceInvoice $invoice, SettleDeliveryAction $action): RedirectResponse
    {
        Gate::authorize('settle', [DeliveryProvider::class, $invoice->branch_id]);
        abort_if($invoice->shipping_provider_id === null, 422, 'الطلب بلا سائق');

        $action->handle($invoice, $request->validated());

        return back()->with('success', "تمت تسوية توصيل {$invoice->invoice_number}");
    }

    /** الإلغاء حذفٌ ناعم للمصروف بسببٍ مسجَّل — «التعديل» إلغاءٌ ثم تسويةٌ جديدة. */
    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        abort_if($expense->delivery_provider_id === null, 404);
        Gate::authorize('settle', [DeliveryProvider::class, $expense->branch_id]);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        DB::transaction(function () use ($expense, $reason) {
            $expense->delete();
            activity('expenses')->performedOn($expense)->causedBy(auth()->user())
                ->withProperties(['reason' => $reason])->log('إلغاء تسوية توصيل');
        });

        return back()->with('success', 'تم إلغاء التسوية');
    }
}
