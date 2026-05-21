<?php

namespace App\Http\Controllers;

use App\Actions\PaymentMethod\CreatePaymentMethodAction;
use App\Actions\PaymentMethod\DeletePaymentMethodAction;
use App\Actions\PaymentMethod\UpdatePaymentMethodAction;
use App\Http\Requests\PaymentMethod\StorePaymentMethodRequest;
use App\Http\Requests\PaymentMethod\UpdatePaymentMethodRequest;
use App\Models\PaymentMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class PaymentMethodController extends Controller
{
    public function store(StorePaymentMethodRequest $request, CreatePaymentMethodAction $action): RedirectResponse
    {
        Gate::authorize('create', PaymentMethod::class);

        $action->handle([
            ...$request->validated(),
            'branch_id' => auth()->user()->branchId,
        ]);

        return back()->with('success', 'تم إضافة طريقة الدفع بنجاح');
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod, UpdatePaymentMethodAction $action): RedirectResponse
    {
        Gate::authorize('update', $paymentMethod);

        $action->handle($paymentMethod, $request->validated());

        return back()->with('success', 'تم تحديث طريقة الدفع بنجاح');
    }

    public function destroy(PaymentMethod $paymentMethod, DeletePaymentMethodAction $action): RedirectResponse
    {
        Gate::authorize('delete', $paymentMethod);

        $action->handle($paymentMethod);

        return back()->with('success', 'تم حذف طريقة الدفع بنجاح');
    }

    public function toggleStatus(PaymentMethod $paymentMethod, UpdatePaymentMethodAction $action): RedirectResponse
    {
        Gate::authorize('update', $paymentMethod);

        $action->handle($paymentMethod, ['is_active' => ! $paymentMethod->is_active]);

        return back()->with('success', 'تم تحديث حالة طريقة الدفع بنجاح');
    }
}
