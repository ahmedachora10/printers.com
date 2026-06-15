<?php

namespace App\Actions\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyConfig;
use Illuminate\Validation\ValidationException;

/**
 * Pure computation for the two customer-facing loyalty benefits applied while
 * building an invoice: the automatic tier discount and points redemption.
 * No writes happen here — the redemption is recorded after the invoice exists
 * via RedeemLoyaltyPointsAction.
 */
class ApplyLoyaltyDiscountsAction
{
    /**
     * Automatic tier discount for the customer, computed off the given base.
     *
     * @return array{0: float, 1: float} [percentage, amount]
     */
    public function tierDiscount(?Customer $customer, bool $eligible, LoyaltyConfig $config, float $base): array
    {
        if (! $eligible || ! $customer || ! $config->is_active) {
            return [0.0, 0.0];
        }

        $pct = $config->discountPctForTier($customer->tier);

        if ($pct <= 0) {
            return [0.0, 0.0];
        }

        return [$pct, round($base * $pct / 100, 2)];
    }

    /**
     * Validate and value a points-redemption request against the running base.
     * redemption_rate is the number of points needed for 1 ر.س of discount.
     *
     * @return array{0: int, 1: float} [pointsRedeemed, discountAmount]
     */
    public function redemption(?Customer $customer, bool $eligible, LoyaltyConfig $config, int $requestedPoints, float $base): array
    {
        if ($requestedPoints <= 0) {
            return [0, 0.0];
        }

        if (! $eligible || ! $customer || ! $config->is_active) {
            throw ValidationException::withMessages([
                'redeem_points' => 'لا يمكن استبدال النقاط لهذا العميل.',
            ]);
        }

        if ($requestedPoints < $config->min_redemption_points) {
            throw ValidationException::withMessages([
                'redeem_points' => "الحد الأدنى للاستبدال هو {$config->min_redemption_points} نقطة.",
            ]);
        }

        if ($requestedPoints > $customer->points_balance) {
            throw ValidationException::withMessages([
                'redeem_points' => "رصيد النقاط غير كافٍ (المتاح {$customer->points_balance}).",
            ]);
        }

        $discount = round($requestedPoints / (float) $config->redemption_rate, 2);

        if ($discount > $base) {
            throw ValidationException::withMessages([
                'redeem_points' => 'قيمة النقاط المستبدلة تتجاوز قيمة الفاتورة، استبدل نقاطاً أقل.',
            ]);
        }

        return [$requestedPoints, $discount];
    }
}
