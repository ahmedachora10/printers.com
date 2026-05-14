<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteCustomerAction
{
    public function handle(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            foreach (['service_invoices', 'product_invoices'] as $table) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                    $linked = \Illuminate\Support\Facades\DB::table($table)
                        ->where('customer_id', $customer->id)
                        ->whereNull('deleted_at')
                        ->exists();

                    if ($linked) {
                        throw ValidationException::withMessages([
                            'customer' => 'لا يمكن حذف العميل لأنه مرتبط بفواتير.',
                        ]);
                    }
                }
            }

            $customer->delete();
        });
    }
}
