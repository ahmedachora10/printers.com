<?php

use App\Enums\CustomerTierEnum;
use App\Enums\Roles;
use App\Models\Agent;
use App\Models\Branch;
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

// Reuses the global makeIndividual() + loyaltyPayload() helpers defined in
// LoyaltyEarningTest. Default payload: 3 × 10 = 30 subtotal, +15% VAT.

describe('Loyalty redemption & tier discount', function () {
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

    it('auto-applies the tier discount for a gold customer', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'gold_discount_pct' => 8]);
        $customer = makeIndividual(['tier' => CustomerTierEnum::Gold, 'cumulative_spend' => 5000]);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        $invoice = ProductInvoice::firstOrFail();
        // subtotal 30، خصم الذهبي 8% = 2.40، فالإجمالي 27.60 شامل الضريبة
        expect((float) $invoice->tier_discount_pct)->toBe(8.00)
            ->and((float) $invoice->tier_discount_amount)->toBe(2.40)
            ->and((float) $invoice->total_amount)->toBe(27.60);
    });

    it('does not give a tier discount to an untiered customer', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id]);
        $customer = makeIndividual(['tier' => CustomerTierEnum::None]);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        expect((float) ProductInvoice::firstOrFail()->tier_discount_amount)->toBe(0.00);
    });

    it('redeems points into a fixed discount and decrements the balance', function () {
        LoyaltyConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'earning_rate' => 1,
            'redemption_rate' => 100,
            'min_redemption_points' => 500,
        ]);
        $customer = makeIndividual(['points_balance' => 1000]);

        post(route('pos.product.store'), loyaltyPayload([
            'customer_id' => $customer->id,
            'redeem_points' => 500,
        ]));

        $invoice = ProductInvoice::firstOrFail();
        // subtotal 30، استبدال 500/100 = 5، فالإجمالي 25 شامل الضريبة
        expect((int) $invoice->points_redeemed)->toBe(500)
            ->and((float) $invoice->points_discount)->toBe(5.00)
            ->and((float) $invoice->total_amount)->toBe(25.00);

        // 1000 − 500 redeemed = 500، ثم اكتساب FLOOR(25 ÷ 1.15) = 21 → 521
        expect($customer->refresh()->points_balance)->toBe(521);

        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $customer->id,
            'type' => 'redeem',
            'points' => -500,
            'balance_after' => 500,
        ]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $customer->id,
            'type' => 'earn',
            'balance_after' => 521,
        ]);
    });

    it('stacks the tier discount and points redemption on the same invoice', function () {
        LoyaltyConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'gold_discount_pct' => 8,
            'redemption_rate' => 100,
            'min_redemption_points' => 500,
        ]);
        $customer = makeIndividual([
            'tier' => CustomerTierEnum::Gold,
            'cumulative_spend' => 5000,
            'points_balance' => 1000,
        ]);

        post(route('pos.product.store'), loyaltyPayload([
            'customer_id' => $customer->id,
            'redeem_points' => 500,
        ]));

        $invoice = ProductInvoice::firstOrFail();
        // 30 − 2.40 خصم فئة − 5 نقاط = 22.60 إجمالاً شاملاً للضريبة
        expect((float) $invoice->tier_discount_amount)->toBe(2.40)
            ->and((float) $invoice->points_discount)->toBe(5.00)
            ->and((float) $invoice->total_amount)->toBe(22.60);
    });

    it('rejects redeeming fewer than the minimum points', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'min_redemption_points' => 500]);
        $customer = makeIndividual(['points_balance' => 1000]);

        post(route('pos.product.store'), loyaltyPayload([
            'customer_id' => $customer->id,
            'redeem_points' => 100,
        ]))->assertSessionHasErrors('redeem_points');

        expect(ProductInvoice::count())->toBe(0);
    });

    it('rejects redeeming more points than the balance', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'min_redemption_points' => 100]);
        $customer = makeIndividual(['points_balance' => 200]);

        post(route('pos.product.store'), loyaltyPayload([
            'customer_id' => $customer->id,
            'redeem_points' => 500,
        ]))->assertSessionHasErrors('redeem_points');

        expect(ProductInvoice::count())->toBe(0);
    });

    it('rejects a points discount that exceeds the invoice amount', function () {
        LoyaltyConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'redemption_rate' => 1, // 1 point = 1 ر.س
            'min_redemption_points' => 1,
        ]);
        $customer = makeIndividual(['points_balance' => 100000]);

        // 1000 points × (1 ر.س/point) = 1000 discount > 30 base → rejected
        post(route('pos.product.store'), loyaltyPayload([
            'customer_id' => $customer->id,
            'redeem_points' => 1000,
        ]))->assertSessionHasErrors('redeem_points');

        expect(ProductInvoice::count())->toBe(0);
    });

    it('rejects redemption for an ineligible (corporate) customer', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'min_redemption_points' => 1]);
        $customer = makeIndividual([
            'customer_type' => 'corporate',
            'company_name' => 'شركة',
            'points_balance' => 1000,
        ]);

        post(route('pos.product.store'), loyaltyPayload([
            'customer_id' => $customer->id,
            'redeem_points' => 500,
        ]))->assertSessionHasErrors('redeem_points');

        expect(ProductInvoice::count())->toBe(0);
        expect(LoyaltyTransaction::count())->toBe(0);
    });

    it('suppresses the tier discount on an agent invoice', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'gold_discount_pct' => 8]);
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'discount', 'rate' => 10]);
        $customer = makeIndividual(['tier' => CustomerTierEnum::Gold, 'cumulative_spend' => 5000]);

        post(route('pos.product.store'), loyaltyPayload([
            'customer_id' => $customer->id,
            'agent_id' => $agent->id,
        ]));

        $invoice = ProductInvoice::firstOrFail();
        // B2B: no tier discount; خصم المندوب 10% من 30 = 3، فالإجمالي 27 شامل الضريبة
        expect((float) $invoice->tier_discount_amount)->toBe(0.00)
            ->and((float) $invoice->agent_discount)->toBe(3.00)
            ->and((float) $invoice->total_amount)->toBe(27.00);
    });
});
