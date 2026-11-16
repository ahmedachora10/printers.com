<?php

namespace App\Actions\PaymentMethod;

use App\Models\PaymentMethod;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdatePaymentMethodAction
{
    /** @param array<string, mixed> $data */
    public function handle(PaymentMethod $paymentMethod, array $data): PaymentMethod
    {
        return DB::transaction(function () use ($paymentMethod, $data) {
            $paymentMethod->update(Arr::except($data, 'branch_ids'));

            // تاسك 242: [] = عامة؛ تبديل الحالة لا يرسل branch_ids فلا يمسّها.
            if (array_key_exists('branch_ids', $data)) {
                $paymentMethod->branches()->sync($data['branch_ids']);
            }

            return $paymentMethod;
        });
    }
}
