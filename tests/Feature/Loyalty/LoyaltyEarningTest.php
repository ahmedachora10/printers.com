<?php

use App\Enums\CustomerTierEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/**
 * Drives loyalty earning through the real product POS endpoint so the wiring
 * inside CreateProductInvoiceAction is exercised end-to-end. Default payload:
 * 3 × 10 = 30 subtotal, +15% VAT = 34.50 total.
 */
function loyaltyPayload(array $overrides = []): array
{
    return array_merge([
        'status' => 'paid',
        'lines' => [
            ['product_id' => test()->product->id, 'qty' => 3, 'unit_price' => 10, 'discount_pct' => 0],
        ],
    ], $overrides);
}

function makeIndividual(array $attrs = []): Customer
{
    return Customer::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'customer_type' => 'individual',
        'agent_id' => null,
        'points_balance' => 0,
        'cumulative_spend' => 0,
        'tier' => CustomerTierEnum::None,
    ], $attrs));
}

describe('Loyalty earning', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($this->accountant);

        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => ProductCategory::factory()->create()->id,
            'unit_id' => ProductUnit::factory()->create()->id,
            'selling_price' => 10.00,
            'cost_price' => 6.00,
            'current_stock' => 0,
        ]);

        StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'type' => 'opening_stock',
            'qty' => 100,
            'created_by' => $this->accountant->id,
        ]);
    });

    it('credits FLOOR(total × earning_rate) points to an individual on a paid invoice', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'earning_rate' => 0.5]);
        $customer = makeIndividual();

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        // الإجمالي شامل الضريبة = 30؛ 30 × 0.5 = 15 → FLOOR = 15
        expect($customer->refresh()->points_balance)->toBe(15)
            ->and((float) $customer->cumulative_spend)->toBe(30.00);

        $invoice = ProductInvoice::firstOrFail();
        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'invoice_type' => ProductInvoice::class,
            'type' => 'earn',
            'points' => 15,
            'balance_after' => 15,
        ]);
    });

    it('accumulates points and balance_after across invoices', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'earning_rate' => 1]);
        $customer = makeIndividual(['points_balance' => 100, 'cumulative_spend' => 100]);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        // 100 + FLOOR(30.00) = 130
        expect($customer->refresh()->points_balance)->toBe(130);
        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $customer->id,
            'points' => 30,
            'balance_after' => 130,
        ]);
    });

    it('promotes the tier once cumulative spend crosses a threshold', function () {
        LoyaltyConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'earning_rate' => 1,
            'bronze_threshold' => 30,
            'silver_threshold' => 2000,
            'gold_threshold' => 5000,
        ]);
        $customer = makeIndividual();

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        // cumulative 34.50 ≥ bronze_threshold 30 → bronze
        expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Bronze);
    });

    it('never downgrades an existing tier', function () {
        LoyaltyConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'earning_rate' => 1,
            'bronze_threshold' => 30,
        ]);
        // Gold customer with low spend — a small invoice must not demote them.
        $customer = makeIndividual(['tier' => CustomerTierEnum::Gold, 'cumulative_spend' => 0]);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Gold);
    });

    it('earns no points for a corporate customer', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id]);
        $customer = makeIndividual(['customer_type' => 'corporate', 'company_name' => 'شركة']);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        expect($customer->refresh()->points_balance)->toBe(0);
        expect(LoyaltyTransaction::count())->toBe(0);
    });

    it('earns no points for an agent-linked customer', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id]);
        $agentUser = User::factory()->create(['branch_id' => $this->branch->id]);
        $customer = makeIndividual(['agent_id' => $agentUser->id]);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        expect($customer->refresh()->points_balance)->toBe(0);
        expect(LoyaltyTransaction::count())->toBe(0);
    });

    it('earns no points on a due invoice', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id]);
        $customer = makeIndividual();

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id, 'status' => 'due']));

        expect($customer->refresh()->points_balance)->toBe(0);
        expect(LoyaltyTransaction::count())->toBe(0);
    });

    it('earns no points when the loyalty program is inactive', function () {
        LoyaltyConfig::factory()->inactive()->create(['branch_id' => $this->branch->id]);
        $customer = makeIndividual();

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        expect($customer->refresh()->points_balance)->toBe(0);
        expect(LoyaltyTransaction::count())->toBe(0);
    });

    it('earns nothing for a cash sale with no customer', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id]);

        post(route('pos.product.store'), loyaltyPayload());

        expect(LoyaltyTransaction::count())->toBe(0);
    });
});
