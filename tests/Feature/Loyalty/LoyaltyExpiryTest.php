<?php

use App\Actions\Loyalty\ExpireLoyaltyPointsAction;
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

uses(RefreshDatabase::class);

function expiryCustomer(array $attrs = []): Customer
{
    return Customer::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'customer_type' => 'individual',
        'agent_id' => null,
        'points_balance' => 400,
        'cumulative_spend' => 900,
        'tier' => CustomerTierEnum::Bronze,
        'created_at' => now()->subYears(3),
    ], $attrs));
}

describe('Loyalty points expiry', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);
        $this->action = app(ExpireLoyaltyPointsAction::class);
    });

    it('zeroes the balance of a customer idle beyond the branch window', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'expiry_months' => 12]);
        $customer = expiryCustomer();

        expect($this->action->handle())->toBe(1);

        expect($customer->refresh()->points_balance)->toBe(0);

        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $customer->id,
            'type' => 'expire',
            'points' => -400,
            'balance_after' => 0,
            'invoice_id' => null,
        ]);
    });

    it('leaves the tier and cumulative spend alone', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'expiry_months' => 12]);
        $customer = expiryCustomer();

        $this->action->handle();

        expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Bronze)
            ->and((float) $customer->cumulative_spend)->toBe(900.00);
    });

    it('never expires anything when the branch set no window', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'expiry_months' => null]);
        $customer = expiryCustomer();

        expect($this->action->handle())->toBe(0)
            ->and($customer->refresh()->points_balance)->toBe(400);
    });

    it('skips a branch whose programme is switched off', function () {
        LoyaltyConfig::factory()->inactive()->create([
            'branch_id' => $this->branch->id,
            'expiry_months' => 12,
        ]);
        $customer = expiryCustomer();

        expect($this->action->handle())->toBe(0)
            ->and($customer->refresh()->points_balance)->toBe(400);
    });

    it('spares a customer who is still inside the window', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'expiry_months' => 12]);
        $customer = expiryCustomer(['created_at' => now()->subMonths(11)]);

        expect($this->action->handle())->toBe(0)
            ->and($customer->refresh()->points_balance)->toBe(400);
    });

    it('spares a customer whose last loyalty movement is recent', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'expiry_months' => 12]);
        $customer = expiryCustomer();

        LoyaltyTransaction::factory()->create([
            'customer_id' => $customer->id,
            'type' => 'earn',
            'points' => 400,
            'balance_after' => 400,
            'created_at' => now()->subMonth(),
        ]);

        expect($this->action->handle())->toBe(0)
            ->and($customer->refresh()->points_balance)->toBe(400);
    });

    // شراءٌ جديد يُصفّر عدّاد الخمول ويُنقذ الرصيد كلّه — المدة تُقرأ للعميل لا
    // لكل دفعة نقاط.
    it('spares a customer whose last invoice is recent even if the points are old', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'expiry_months' => 12]);
        $customer = expiryCustomer();

        LoyaltyTransaction::factory()->create([
            'customer_id' => $customer->id,
            'type' => 'earn',
            'points' => 400,
            'balance_after' => 400,
            'created_at' => now()->subYears(2),
        ]);

        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        $product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => ProductCategory::factory()->create()->id,
            'unit_id' => ProductUnit::factory()->create()->id,
            'selling_price' => 10.00,
            'cost_price' => 6.00,
        ]);

        StockMovement::factory()->create([
            'product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'type' => 'opening_stock',
            'qty' => 50,
            'created_by' => $accountant->id,
        ]);

        $this->actingAs($accountant)->post(route('pos.product.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'customer_id' => $customer->id,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0]],
        ])->assertRedirect();

        // فاتورة آجلة لم تُسدَّد بعد، فلا اكتساب — ومع ذلك هي دليل حياة الحساب
        expect(ProductInvoice::count())->toBe(1);

        expect($this->action->handle())->toBe(0)
            ->and($customer->refresh()->points_balance)->toBe(400);
    });

    it('ignores a customer with no points left', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'expiry_months' => 12]);
        expiryCustomer(['points_balance' => 0]);

        expect($this->action->handle())->toBe(0);
        $this->assertDatabaseCount('loyalty_transactions', 0);
    });

    it('only touches the requested branch', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'expiry_months' => 12]);
        $mine = expiryCustomer();

        $other = Branch::factory()->create();
        LoyaltyConfig::factory()->create(['branch_id' => $other->id, 'expiry_months' => 12]);
        $theirs = Customer::factory()->create([
            'branch_id' => $other->id,
            'customer_type' => 'individual',
            'agent_id' => null,
            'points_balance' => 400,
            'created_at' => now()->subYears(3),
        ]);

        expect($this->action->handle($this->branch->id))->toBe(1);

        expect($mine->refresh()->points_balance)->toBe(0)
            ->and($theirs->refresh()->points_balance)->toBe(400);
    });

    it('is safe to run twice in a row', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'expiry_months' => 12]);
        expiryCustomer();

        $this->action->handle();

        expect($this->action->handle())->toBe(0);
        expect(LoyaltyTransaction::where('type', 'expire')->count())->toBe(1);
    });

    it('runs from the console command', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'expiry_months' => 12]);
        $customer = expiryCustomer();

        $this->artisan('loyalty:expire-points')->assertSuccessful();

        expect($customer->refresh()->points_balance)->toBe(0);
    });

    it('accepts a branch option on the command', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'expiry_months' => 12]);
        $customer = expiryCustomer();

        $other = Branch::factory()->create();
        LoyaltyConfig::factory()->create(['branch_id' => $other->id, 'expiry_months' => 12]);
        $theirs = Customer::factory()->create([
            'branch_id' => $other->id,
            'customer_type' => 'individual',
            'agent_id' => null,
            'points_balance' => 400,
            'created_at' => now()->subYears(3),
        ]);

        $this->artisan('loyalty:expire-points', ['--branch' => $other->id])->assertSuccessful();

        expect($customer->refresh()->points_balance)->toBe(400)
            ->and($theirs->refresh()->points_balance)->toBe(0);
    });
});
