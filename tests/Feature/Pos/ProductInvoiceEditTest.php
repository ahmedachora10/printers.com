<?php

use App\Enums\CustomerTierEnum;
use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\ProductUnit;
use App\Models\Refund;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function productEditPayload(float $qty, array $overrides = []): array
{
    return array_merge([
        'payment_method_id' => paymentMethodId(),
        'lines' => [
            ['product_id' => test()->product->id, 'qty' => $qty, 'unit_price' => 10, 'discount_pct' => 0],
        ],
    ], $overrides);
}

/** Sells 3 × 10 through the real POS as the accountant, then acts as the branch admin. */
function sellThenActAsBranchAdmin(array $overrides = []): ProductInvoice
{
    test()->actingAs(test()->accountant)
        ->post(route('pos.product.store'), productEditPayload(3, ['status' => 'paid', ...$overrides]));

    test()->actingAs(test()->branchAdmin);

    return ProductInvoice::latest('id')->firstOrFail();
}

function invoiceStockQuantities(ProductInvoice $invoice): array
{
    return StockMovement::query()
        ->where('reference_type', ProductInvoice::class)
        ->where('reference_id', $invoice->id)
        ->orderBy('id')
        ->get()
        ->map(fn ($m) => [$m->type->value, (float) $m->qty])
        ->all();
}

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->addRole(Roles::ACCOUNTANT->value);

    $this->branchAdmin = User::factory()->create();
    $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
    $this->branch->update(['owner_id' => $this->branchAdmin->id]);

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
        'type' => StockMovementTypeEnum::OPENING_STOCK,
        'qty' => 100,
        'created_by' => $this->accountant->id,
    ]);
});

it('opens the POS screen on the invoice for the branch admin', function () {
    $invoice = sellThenActAsBranchAdmin();

    $this->get(route('pos.product.edit', $invoice))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('pos/product/index')
            ->where('invoice.invoiceNumber', $invoice->invoice_number)
            // 97 in stock + the 3 this invoice already holds.
            ->where('invoice.lines.0.maxStock', 100));
});

it('re-prices a paid invoice and moves stock by the difference only', function () {
    $invoice = sellThenActAsBranchAdmin();

    $this->put(route('pos.product.update', $invoice), productEditPayload(5))
        ->assertRedirect(route('invoices.show', ['type' => 'product', 'id' => $invoice->id]));

    $invoice->refresh();

    expect((float) $invoice->total_amount)->toBe(50.00)
        ->and($invoice->status->value)->toBe('paid')
        ->and($invoice->lines)->toHaveCount(1)
        ->and($this->product->refresh()->current_stock)->toEqual(95)
        ->and(invoiceStockQuantities($invoice))->toBe([['sale_out', -3.0], ['sale_out', -2.0]]);

    $this->put(route('pos.product.update', $invoice), productEditPayload(1));

    expect($this->product->refresh()->current_stock)->toEqual(99)
        ->and(invoiceStockQuantities($invoice)[2])->toBe(['return_in', 4.0]);
});

it('settles a changed total on an invoice paid by instalments with one new payment row', function () {
    $invoice = sellThenActAsBranchAdmin();
    $invoice->payments()->create([
        'branch_id' => $invoice->branch_id,
        'amount' => 30,
        'paid_at' => now(),
        'recorded_by' => $this->accountant->id,
    ]);

    $this->put(route('pos.product.update', $invoice), productEditPayload(2))->assertRedirect();

    expect($invoice->payments()->orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([30.0, -10.0])
        ->and($invoice->refresh()->paidAmount())->toBe(20.00);
});

it('recomputes earned loyalty points instead of stacking them', function () {
    LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'earning_rate' => 0.5]);
    $customer = Customer::factory()->create([
        'branch_id' => $this->branch->id,
        'customer_type' => 'individual',
        'agent_id' => null,
        'points_balance' => 0,
        'cumulative_spend' => 0,
        'tier' => CustomerTierEnum::None,
    ]);

    $invoice = sellThenActAsBranchAdmin(['customer_id' => $customer->id]);
    expect($customer->refresh()->points_balance)->toBe(13);

    // 50 ÷ 1.15 = 43.48 × 0.5 → 21.
    $this->put(route('pos.product.update', $invoice), productEditPayload(5, ['customer_id' => $customer->id]));
    expect($customer->refresh()->points_balance)->toBe(21)
        ->and((float) $customer->cumulative_spend)->toBe(50.00);

    // وتعديلٌ ثانٍ لا يسحب الاكتساب الأول مرة أخرى.
    $this->put(route('pos.product.update', $invoice), productEditPayload(3, ['customer_id' => $customer->id]));
    expect($customer->refresh()->points_balance)->toBe(13)
        ->and((float) $customer->cumulative_spend)->toBe(30.00);
});

it('forbids the accountant from editing a product invoice', function () {
    $invoice = sellThenActAsBranchAdmin();
    $this->actingAs($this->accountant);

    $this->get(route('pos.product.edit', $invoice))->assertForbidden();
    $this->put(route('pos.product.update', $invoice), productEditPayload(5))->assertForbidden();
});

it('forbids editing an invoice that carries a refund', function () {
    $invoice = sellThenActAsBranchAdmin();
    Refund::create([
        'branch_id' => $this->branch->id,
        'user_id' => $this->accountant->id,
        'source_type' => 'product',
        'invoice_id' => $invoice->id,
        'invoice_type' => ProductInvoice::class,
        'amount' => 5,
        'reason' => 'تالف',
        'stock_reversed' => false,
    ]);

    $this->put(route('pos.product.update', $invoice), productEditPayload(5))->assertForbidden();
});
