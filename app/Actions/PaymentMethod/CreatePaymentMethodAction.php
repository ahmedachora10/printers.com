<?php

namespace App\Actions\PaymentMethod;

use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

class CreatePaymentMethodAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): PaymentMethod
    {
        return DB::transaction(fn () => PaymentMethod::create($data));
    }
}
