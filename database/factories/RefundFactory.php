<?php

namespace Database\Factories;

use App\Enums\InvoiceTypeEnum;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'source_type' => InvoiceTypeEnum::PRODUCT,
            'invoice_id' => null,
            'invoice_type' => ProductInvoice::class,
            'amount' => fake()->randomFloat(2, 10, 500),
            'reason' => fake()->sentence(),
            'stock_reversed' => false,
        ];
    }

    public function service(): static
    {
        return $this->state([
            'source_type' => InvoiceTypeEnum::SERVICE,
            'invoice_type' => ServiceInvoice::class,
        ]);
    }
}
