<?php

namespace App\Actions\PaymentMethod;

use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

class UpdatePaymentMethodAction
{
    public function handle(PaymentMethod $paymentMethod, array $data): PaymentMethod
    {
        return DB::transaction(function () use ($paymentMethod, $data) {
            $paymentMethod->update($data);

            return $paymentMethod;
        });
    }
}
