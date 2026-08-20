<?php

namespace App\Actions\ServiceInvoice\Concerns;

use App\Enums\CommissionSourceTypeEnum;
use App\Enums\LoyaltyTransactionTypeEnum;
use App\Models\CommissionLedger;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;

/**
 * Unwinds the accruals a service invoice created, using the immutable-ledger
 * pattern (reversals are new offsetting rows, never updates/deletes). Shared by
 * cancel, edit and return. Call each helper inside the surrounding transaction.
 */
trait ReversesServiceInvoiceAccruals
{
    /**
     * Reverse every unpaid commission row for the invoice's lines in full by
     * inserting a negative offsetting row. Paid commission is left untouched.
     */
    protected function reverseUnpaidCommission(ServiceInvoice $invoice): void
    {
        $lineIds = $invoice->lines()->pluck('id');

        if ($lineIds->isEmpty()) {
            return;
        }

        $entries = CommissionLedger::query()
            ->where('invoice_line_type', ServiceInvoiceLine::class)
            ->whereIn('invoice_line_id', $lineIds)
            ->whereNull('paid_at')
            ->where('amount', '>', 0)
            ->lockForUpdate()
            ->get();

        foreach ($entries as $entry) {
            CommissionLedger::create([
                'user_id' => $entry->user_id,
                'branch_id' => $entry->branch_id,
                'invoice_line_id' => $entry->invoice_line_id,
                'invoice_line_type' => $entry->invoice_line_type,
                'amount' => -1 * (float) $entry->amount,
                'is_tahazir' => $entry->is_tahazir,
                'tier_applied' => $entry->tier_applied,
                'source_type' => CommissionSourceTypeEnum::STANDARD,
                'earned_at' => now(),
            ]);
        }
    }

    /**
     * Return points the customer redeemed on this invoice. Redemption runs at
     * creation regardless of status, so unwinding the invoice must give them
     * back via a positive manual adjustment.
     */
    protected function restoreRedeemedPoints(ServiceInvoice $invoice): void
    {
        $points = (int) $invoice->points_redeemed;

        if ($points <= 0 || $invoice->customer_id === null) {
            return;
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()
            ->whereKey($invoice->customer_id)
            ->lockForUpdate()
            ->first();

        if (! $customer) {
            return;
        }

        $newBalance = $customer->points_balance + $points;
        $customer->update(['points_balance' => $newBalance]);

        LoyaltyTransaction::create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'invoice_type' => $invoice->getMorphClass(),
            'type' => LoyaltyTransactionTypeEnum::ManualAdjust,
            'points' => $points,
            'balance_after' => $newBalance,
            'notes' => "استرجاع نقاط بعد إلغاء/استرجاع الفاتورة {$invoice->invoice_number}",
        ]);
    }

    /**
     * Claw back points the customer *earned* on this (paid) invoice when it is
     * returned: subtract them from the balance (never below zero) and roll back
     * the gross spend they added — the same measure the earn added. The tier is
     * then re-derived from the reduced spend, so a return that drops a customer
     * below a threshold drops the tier with it. The reversal is recorded as an
     * immutable negative manual adjustment.
     */
    protected function clawBackEarnedPoints(ServiceInvoice $invoice): void
    {
        if ($invoice->customer_id === null) {
            return;
        }

        $earned = (int) LoyaltyTransaction::query()
            ->where('invoice_id', $invoice->id)
            ->where('invoice_type', $invoice->getMorphClass())
            ->where('type', LoyaltyTransactionTypeEnum::Earn)
            ->sum('points');

        if ($earned <= 0) {
            return;
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()
            ->whereKey($invoice->customer_id)
            ->lockForUpdate()
            ->first();

        if (! $customer) {
            return;
        }

        $removed = min($earned, $customer->points_balance);
        $newBalance = $customer->points_balance - $removed;
        $newSpend = max(0, (float) $customer->cumulative_spend - (float) $invoice->total_amount);

        $customer->update([
            'points_balance' => $newBalance,
            'cumulative_spend' => $newSpend,
            'tier' => LoyaltyConfig::forBranch($customer->branch_id)->tierForSpend($newSpend),
        ]);

        LoyaltyTransaction::create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'invoice_type' => $invoice->getMorphClass(),
            'type' => LoyaltyTransactionTypeEnum::ManualAdjust,
            'points' => -$removed,
            'balance_after' => $newBalance,
            'notes' => "سحب نقاط مكتسبة بعد استرجاع الفاتورة {$invoice->invoice_number}",
        ]);
    }

    /**
     * Give back a coupon's capacity when its invoice is unwound (guards against
     * dropping below zero).
     */
    protected function releaseCoupon(ServiceInvoice $invoice): void
    {
        if ($invoice->coupon_id === null) {
            return;
        }

        Coupon::query()
            ->whereKey($invoice->coupon_id)
            ->where('used_count', '>', 0)
            ->decrement('used_count');
    }
}
