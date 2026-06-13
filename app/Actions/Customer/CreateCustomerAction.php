<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class CreateCustomerAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data, int $branchId): Customer
    {
        return DB::transaction(function () use ($data, $branchId) {
            return Customer::create(array_merge($data, ['branch_id' => $branchId]));
        });
    }
}
