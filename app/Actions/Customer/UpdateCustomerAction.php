<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\CustomerBranch;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateCustomerAction
{
    /**
     * الهوية على العميل (تظهر في كل فروعه)، وحقول الفرع على حسابه في $branchId.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Customer $customer, array $data, ?int $branchId = null): Customer
    {
        return DB::transaction(function () use ($customer, $data, $branchId) {
            $customer->update(Arr::only($data, Customer::IDENTITY_FIELDS));

            $account = Arr::only($data, CustomerBranch::FIELDS);

            if ($branchId !== null && $account !== []) {
                $customer->accountFor($branchId)?->update($account);
            }

            return $customer->refresh();
        });
    }
}
