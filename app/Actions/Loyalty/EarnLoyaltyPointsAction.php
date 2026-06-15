<?php

namespace App\Actions\Loyalty;

use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use App\Enums\InvoiceStatusEnum;
use App\Enums\LoyaltyTransactionTypeEnum;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;

class EarnLoyaltyPointsAction
{
    /**
     * Credit loyalty points for a paid invoice. Points are earned only on
     * Paid invoices for individual, non-agent-linked customers (business rule).
     * Writes an immutable earn transaction, bumps the balance and cumulative
     * spend, and promotes the tier (tiers never downgrade).
     *
     * Safe to call standalone (future due→paid transition) or nested inside an
     * invoice-creation transaction. Returns null when no points were earned.
     */
    public function handle(ProductInvoice|ServiceInvoice $invoice): ?LoyaltyTransaction
    {
        if ($invoice->status !== InvoiceStatusEnum::PAID || $invoice->customer_id === null) {
            return null;
        }

        // B2B sales (agent-linked customer or an agent override on the invoice)
        // are settled via rebate/discount, never loyalty points.
        if ($invoice->agent_id !== null) {
            return null;
        }

        return DB::transaction(function () use ($invoice) {
            /** @var Customer|null $customer */
            $customer = Customer::query()
                ->whereKey($invoice->customer_id)
                ->lockForUpdate()
                ->first();

            if (! $customer
                || $customer->customer_type !== CustomerTypeEnum::Individual
                || $customer->agent_id !== null
            ) {
                return null;
            }

            $config = LoyaltyConfig::forBranch($invoice->branch_id);

            if (! $config->is_active) {
                return null;
            }

            $earned = (int) floor((float) $invoice->total_amount * (float) $config->earning_rate);

            if ($earned <= 0) {
                return null;
            }

            $newBalance = $customer->points_balance + $earned;
            $newSpend = (float) $customer->cumulative_spend + (float) $invoice->total_amount;
            $tier = $this->resolveTier($customer->tier, $newSpend, $config);

            $customer->update([
                'points_balance' => $newBalance,
                'cumulative_spend' => $newSpend,
                'tier' => $tier,
            ]);

            return LoyaltyTransaction::create([
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
                'invoice_type' => $invoice->getMorphClass(),
                'type' => LoyaltyTransactionTypeEnum::Earn,
                'points' => $earned,
                'balance_after' => $newBalance,
            ]);
        });
    }

    /**
     * Tier from cumulative spend against the branch thresholds, but never
     * below the customer's current tier (tiers never downgrade automatically).
     */
    private function resolveTier(CustomerTierEnum $current, float $spend, LoyaltyConfig $config): CustomerTierEnum
    {
        $computed = match (true) {
            $spend >= (float) $config->gold_threshold => CustomerTierEnum::Gold,
            $spend >= (float) $config->silver_threshold => CustomerTierEnum::Silver,
            $spend >= (float) $config->bronze_threshold => CustomerTierEnum::Bronze,
            default => CustomerTierEnum::None,
        };

        return $computed->rank() > $current->rank() ? $computed : $current;
    }
}
