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

        // B2B sales (agent-linked customer or an agent on the invoice) are
        // settled via rebate/discount, never loyalty points.
        if ($this->hasAgent($invoice)) {
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

            // النقاط والإنفاق التراكمي يقومان على قيمة الفاتورة صافيةً من ضريبة
            // القيمة المضافة: العميل لا يكسب على ضريبةٍ تذهب إلى الدولة، وهي
            // القاعدة نفسها التي تحكم كل نسبة عمولة في النظام.
            $base = $invoice->netAmount();

            $earned = (int) floor($base * (float) $config->earning_rate);

            if ($earned <= 0) {
                return null;
            }

            $newBalance = $customer->points_balance + $earned;
            $newSpend = (float) $customer->cumulative_spend + $base;
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
     * Is this invoice a B2B (agent) sale? The two invoice types carry their
     * agents differently: a service invoice may have several, held on the
     * service_invoice_agent pivot (the scalar agent_id was dropped from
     * service_invoices), while a product invoice keeps a single agent_id
     * column. Reading agent_id off a service invoice silently yields null, so
     * the type must be branched on explicitly.
     */
    private function hasAgent(ProductInvoice|ServiceInvoice $invoice): bool
    {
        return $invoice instanceof ServiceInvoice
            ? $invoice->agents()->exists()
            : $invoice->agent_id !== null;
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
