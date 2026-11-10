<?php

namespace Database\Factories;

use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;
use WeakMap;

/**
 * العميل هويةٌ واحدة، وحسابه في كل فرعٍ صفٌّ في customer_branch. الحالات والاستدعاءات
 * تمرّر `branch_id` وحقول الحساب كما كانت على العميل؛ تُنزع عند البناء وتصير حسابه
 * في ذلك الفرع بعد الإنشاء.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /** @var WeakMap<Customer, array<string, mixed>>|null */
    private static ?WeakMap $accounts = null;

    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->numerify('05########'),
            'email' => fake()->optional(0.6)->safeEmail(),
            'branch_id' => Branch::factory(),
            'customer_type' => CustomerTypeEnum::Individual,
            'company_name' => null,
            'credit_limit' => null,
            'agent_id' => null,
            'points_balance' => fake()->numberBetween(0, 5000),
            'cumulative_spend' => fake()->randomFloat(2, 0, 50000),
            'tier' => CustomerTierEnum::None,
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        self::$accounts ??= new WeakMap;

        return $this
            ->afterMaking(function (Customer $customer) {
                $fields = ['branch_id', ...CustomerBranch::FIELDS];
                self::$accounts[$customer] = Arr::only($customer->getAttributes(), $fields);
                $customer->setRawAttributes(Arr::except($customer->getAttributes(), $fields));
            })
            ->afterCreating(function (Customer $customer) {
                $account = self::$accounts[$customer] ?? [];

                if (isset($account['branch_id'])) {
                    // الحساب قائمٌ منذ أُنشئ العميل في فرعه.
                    $customer->attachTo((int) $account['branch_id'], [
                        ...Arr::except($account, 'branch_id'),
                        'created_at' => $customer->created_at,
                    ]);
                }
            });
    }

    public function corporate(): static
    {
        return $this->state(fn () => [
            'customer_type' => CustomerTypeEnum::Corporate,
            'company_name' => fake()->company(),
        ]);
    }

    public function withCreditLimit(float $limit = 5000): static
    {
        return $this->state(fn () => ['credit_limit' => $limit]);
    }

    public function bronze(): static
    {
        return $this->state(fn () => [
            'tier' => CustomerTierEnum::Bronze,
            'cumulative_spend' => fake()->randomFloat(2, 500, 1999),
        ]);
    }

    public function silver(): static
    {
        return $this->state(fn () => [
            'tier' => CustomerTierEnum::Silver,
            'cumulative_spend' => fake()->randomFloat(2, 2000, 4999),
        ]);
    }

    public function gold(): static
    {
        return $this->state(fn () => [
            'tier' => CustomerTierEnum::Gold,
            'cumulative_spend' => fake()->randomFloat(2, 5000, 100000),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
