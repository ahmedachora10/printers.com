<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\CustomerBranch;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateCustomerAction
{
    /**
     * عميلٌ مسجَّل بجواله في فرعٍ آخر يُربط بهذا الفرع ولا يُنسخ: هويته المسجَّلة
     * تبقى (المكتوب يملأ فارغها)، وحقول الفرع من النموذج على حسابه الجديد.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, int $branchId): Customer
    {
        return DB::transaction(function () use ($data, $branchId) {
            $customer = Customer::forPhone($data['phone']);
            $customer->fillIdentity($data)->save();
            $customer->attachTo($branchId, Arr::only($data, CustomerBranch::FIELDS));

            return $customer;
        });
    }
}
