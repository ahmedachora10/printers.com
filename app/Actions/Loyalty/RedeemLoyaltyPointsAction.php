<?php

namespace App\Actions\Loyalty;

use App\Enums\LoyaltyTransactionTypeEnum;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a points redemption against a created invoice: locks the customer,
 * re-checks the balance (authoritative guard against concurrent redemptions),
 * decrements it, and writes one immutable redeem transaction (negative points).
 *
 * Call within the invoice-creation transaction, after the invoice row exists.
 */
class RedeemLoyaltyPointsAction
{
    public function handle(ProductInvoice|ServiceInvoice $invoice, int $customerId, int $points): ?LoyaltyTransaction
    {
        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($invoice, $customerId, $points) {
            /** @var Customer|null $customer */
            $customer = Customer::query()
                ->whereKey($customerId)
                ->lockForUpdate()
                ->first();

            if (! $customer || $customer->points_balance < $points) {
                throw ValidationException::withMessages([
                    'redeem_points' => 'رصيد النقاط غير كافٍ.',
                ]);
            }

            $newBalance = $customer->points_balance - $points;
            $customer->update(['points_balance' => $newBalance]);

            return LoyaltyTransaction::create([
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
                'invoice_type' => $invoice->getMorphClass(),
                'type' => LoyaltyTransactionTypeEnum::Redeem,
                'points' => -$points,
                'balance_after' => $newBalance,
            ]);
        });
    }
}
