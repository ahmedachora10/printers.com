<?php

namespace App\Http\Controllers;

use App\Actions\InvoicePayment\ChangePaymentMethodAction;
use App\Http\Requests\ServiceInvoice\UpdateInvoicePaymentMethodRequest;
use App\Models\InvoicePayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * طريقة الدفع — على الفاتورة أو على صفّ دفعةٍ منها، لكلا نوعي الفواتير، قبل
 * الاعتماد وبعده (تاسك 99). back() لا وجهةٌ ثابتة: المحاسب يصحّحها من طابور
 * المراجعة ومن شاشة الفاتورة، ويبقى حيث كان.
 */
class InvoicePaymentMethodController extends Controller
{
    public function update(UpdateInvoicePaymentMethodRequest $request, ChangePaymentMethodAction $action): RedirectResponse
    {
        $target = $request->target();
        $invoice = $target instanceof InvoicePayment ? $target->invoice : $target;
        abort_if($invoice === null, 404);
        Gate::authorize('changePaymentMethod', $invoice);

        $action->handle($target, (int) $request->validated('payment_method_id'), $request->file('receipt'), $request->user());

        return back(fallback: route('invoices.index'))
            ->with('success', "تم تحديث طريقة الدفع للفاتورة {$invoice->invoice_number}");
    }
}
