<?php

namespace App\Actions\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyConfig;
use Illuminate\Validation\ValidationException;

/**
 * Pure computation for the two customer-facing loyalty benefits applied while
 * building an invoice: the automatic tier discount and points redemption.
 * No writes happen here — the points are only *booked* onto the invoice; they
 * leave the customer's balance when the invoice is approved, through
 * RedeemLoyaltyPointsAction. Until then they are reserved, which is why the
 * redemption is validated against the available balance, not the raw one.
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
     * @param  int|null  $availablePoints  الرصيد المتاح بعد طرح النقاط المحجوزة على
     *                                     فواتير العميل غير المعتمدة (ResolveAvailablePointsAction).
     *                                     يقع على الرصيد المسجَّل حين لا يُمرَّر.
     * @return array{0: int, 1: float} [pointsRedeemed, discountAmount]
     */
    public function redemption(?Customer $customer, bool $eligible, LoyaltyConfig $config, int $requestedPoints, float $base, ?int $availablePoints = null): array
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

        // المتاح لا الرصيد: نقاطٌ محجوزة على فاتورة أخرى تنتظر الاعتماد لا تُستبدل
        // مرة ثانية، وإن كانت ما زالت ظاهرةً في الرصيد.
        $available = $availablePoints ?? (int) $customer->points_balance;

        if ($requestedPoints > $available) {
            $reserved = (int) $customer->points_balance - $available;

            throw ValidationException::withMessages([
                'redeem_points' => $reserved > 0
                    ? "رصيد النقاط غير كافٍ (المتاح {$available} بعد حجز {$reserved} نقطة على فواتير لم تُعتمد بعد)."
                    : "رصيد النقاط غير كافٍ (المتاح {$available}).",
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
