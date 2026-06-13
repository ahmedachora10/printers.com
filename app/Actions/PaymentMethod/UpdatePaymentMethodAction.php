<?php

namespace App\Actions\PaymentMethod;

use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

class UpdatePaymentMethodAction
{
    /** @param array<string, mixed> $data */
    public function handle(PaymentMethod $paymentMethod, array $data): PaymentMethod
    {
        return DB::transaction(function () use ($paymentMethod, $data) {
            $paymentMethod->update($data);

            return $paymentMethod;
        });
    }
}
