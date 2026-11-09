<?php

namespace Database\Factories;

use App\Enums\LoyaltyTransactionTypeEnum;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\LoyaltyTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyTransaction>
 */
class LoyaltyTransactionFactory extends Factory
{
    protected $model = LoyaltyTransaction::class;

    public function definition(): array
    {
        $points = fake()->numberBetween(1, 500);

        return [
            'customer_id' => Customer::factory(),
            // الحركة في فرع حساب العميل (حسابه الأول حين لا يُمرَّر فرع).
            'branch_id' => fn (array $attributes) => CustomerBranch::where('customer_id', $attributes['customer_id'])->value('branch_id'),
            'invoice_id' => null,
            'invoice_type' => null,
            'type' => LoyaltyTransactionTypeEnum::Earn,
            'points' => $points,
            'balance_after' => $points,
            'notes' => null,
        ];
    }
}
