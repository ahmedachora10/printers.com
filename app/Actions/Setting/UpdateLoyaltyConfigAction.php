<?php

namespace App\Actions\Setting;

use App\Models\LoyaltyConfig;
use Illuminate\Support\Facades\DB;

class UpdateLoyaltyConfigAction
{
    /**
     * Update the branch's loyalty configuration. Earning rules, redemption
     * rates and tier thresholds/discounts only affect future invoices —
     * existing balances, tiers and ledger rows are untouched.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, int $branchId): LoyaltyConfig
    {
        return DB::transaction(function () use ($data, $branchId) {
            $config = LoyaltyConfig::forBranch($branchId);
            $config->update($data);

            return $config;
        });
    }
}
