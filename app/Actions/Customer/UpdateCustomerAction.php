<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class UpdateCustomerAction
{
    /** @param array<string, mixed> $data */
    public function handle(Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($customer, $data) {
            $customer->update($data);

            return $customer->refresh();
        });
    }
}
