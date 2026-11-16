<?php

namespace App\Actions\PaymentMethod;

use App\Models\PaymentMethod;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreatePaymentMethodAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): PaymentMethod
    {
        return DB::transaction(function () use ($data) {
            $method = PaymentMethod::create(Arr::except($data, 'branch_ids'));
            $method->branches()->sync($data['branch_ids'] ?? []);

            return $method;
        });
    }
}
