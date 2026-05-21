<?php

namespace App\Actions\PaymentMethod;

use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeletePaymentMethodAction
{
    public function handle(PaymentMethod $paymentMethod): void
    {
        if ($paymentMethod->isReferencedByInvoices()) {
            throw ValidationException::withMessages([
                'payment_method' => 'لا يمكن حذف طريقة الدفع لأنها مرتبطة بفواتير.',
            ]);
        }

        DB::transaction(fn () => $paymentMethod->delete());
    }
}
