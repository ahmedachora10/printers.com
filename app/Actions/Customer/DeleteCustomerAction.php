<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeleteCustomerAction
{
    /**
     * بفرعٍ: يفصل العميل عن ذلك الفرع وحده ويبقى في فروعه الأخرى. بلا فرع (السوبر
     * أدمن): يحذفه من كل الفروع. وفي الحالتين لا يُمسّ عميلٌ له فواتير هناك.
     */
    public function handle(Customer $customer, ?int $branchId = null): void
    {
        DB::transaction(function () use ($customer, $branchId) {
            foreach (['service_invoices', 'product_invoices'] as $table) {
                if (Schema::hasTable($table)) {
                    $linked = DB::table($table)
                        ->where('customer_id', $customer->id)
                        ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                        ->whereNull('deleted_at')
                        ->exists();

                    if ($linked) {
                        throw ValidationException::withMessages([
                            'customer' => 'لا يمكن حذف العميل لأنه مرتبط بفواتير.',
                        ]);
                    }
                }
            }

            $branchId === null
                ? $customer->delete()
                : $customer->accountFor($branchId)?->delete();
        });
    }
}
