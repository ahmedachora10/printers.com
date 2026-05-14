<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MergeCustomersAction
{
    public function handle(Customer $primary, Customer $secondary): Customer
    {
        return DB::transaction(function () use ($primary, $secondary) {
            foreach (['service_invoices', 'product_invoices'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)
                        ->where('customer_id', $secondary->id)
                        ->update(['customer_id' => $primary->id]);
                }
            }

            if (Schema::hasTable('loyalty_transactions')) {
                DB::table('loyalty_transactions')
                    ->where('customer_id', $secondary->id)
                    ->update(['customer_id' => $primary->id]);
            }

            $primary->increment('points_balance', max(0, $secondary->points_balance));

            $secondary->delete();

            return $primary->refresh();
        });
    }
}
