<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MergeCustomersAction
{
    /**
     * يذوب $secondary في $primary: فواتيره وحركات ولائه وعناوينه وعروض أسعاره
     * تنتقل، وحساب كل فرعٍ ينتقل كما هو — إلا فرعاً للأصليّ فيه حساب، فتُجمع
     * النقاط والإنفاق ويبقى المستوى الأعلى.
     */
    public function handle(Customer $primary, Customer $secondary): Customer
    {
        return DB::transaction(function () use ($primary, $secondary) {
            foreach (['service_invoices', 'product_invoices', 'quotations', 'loyalty_transactions', 'customer_addresses'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)
                        ->where('customer_id', $secondary->id)
                        ->update(['customer_id' => $primary->id]);
                }
            }

            foreach ($secondary->accounts as $account) {
                $target = $primary->accountFor($account->branch_id);

                if ($target === null) {
                    $account->update(['customer_id' => $primary->id]);

                    continue;
                }

                $target->update([
                    'points_balance' => $target->points_balance + max(0, $account->points_balance),
                    'cumulative_spend' => round((float) $target->cumulative_spend + (float) $account->cumulative_spend, 2),
                    'tier' => $account->tier->rank() > $target->tier->rank() ? $account->tier : $target->tier,
                ]);
                $account->delete();
            }

            $secondary->delete();

            return $primary->refresh();
        });
    }
}
