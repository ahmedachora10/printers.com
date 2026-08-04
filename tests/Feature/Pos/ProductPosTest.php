<?php

use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function posPayload(array $overrides = []): array
{
    return array_merge([
        'status' => 'paid',
        'lines' => [
            ['product_id' => test()->product->id, 'qty' => 3, 'unit_price' => 10, 'discount_pct' => 0],
        ],
    ], $overrides);
}

describe('Product POS', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($this->accountant);

        $this->category = ProductCategory::factory()->create();
        $this->unit = ProductUnit::factory()->create();

        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'selling_price' => 10.00,
            'cost_price' => 6.00,
            'current_stock' => 0,
        ]);

        // Seed opening stock through the ledger so current_stock = 100.
        StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementTypeEnum::OPENING_STOCK,
            'qty' => 100,
            'created_by' => $this->accountant->id,
        ]);
        $this->product->refresh();
    });

    it('lets an accountant open the product POS screen', function () {
        $this->get(route('pos.product.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('pos/product/index'));
    });

    it('prevents an employee from opening the product POS screen', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($employee);

        $this->get(route('pos.product.create'))->assertForbidden();
    });

    it('creates a paid invoice with correct totals and decrements stock', function () {
        $this->post(route('pos.product.store'), posPayload())
            ->assertRedirect(route('pos.product.create'))
            ->assertSessionHas('success');

        $invoice = ProductInvoice::firstOrFail();

        expect($invoice->status->value)->toBe('paid')
            ->and($invoice->paid_at)->not->toBeNull()
            ->and((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->vat_amount)->toBe(4.50)
            ->and((float) $invoice->total_amount)->toBe(34.50)
            ->and($invoice->invoice_number)->toBe(sprintf('INV-%03d-%05d', $this->branch->id, 1))
            ->and($invoice->lines)->toHaveCount(1);

        expect($this->product->refresh()->current_stock)->toBe(97);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'type' => 'sale_out',
            'qty' => -3,
            'reference_type' => ProductInvoice::class,
            'reference_id' => $invoice->id,
        ]);
    });

    it('applies a line discount to the subtotal', function () {
        $this->post(route('pos.product.store'), posPayload([
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 10, 'discount_pct' => 50],
            ],
        ]));

        $invoice = ProductInvoice::firstOrFail();
        // 2 * 10 * 0.5 = 10 subtotal, VAT 15% = 1.5, total 11.5
        expect((float) $invoice->subtotal)->toBe(10.00)
            ->and((float) $invoice->total_amount)->toBe(11.50);
    });

    it('saves a due invoice with no paid_at', function () {
        $this->post(route('pos.product.store'), posPayload(['status' => 'due']));

        $invoice = ProductInvoice::firstOrFail();
        expect($invoice->status->value)->toBe('due')
            ->and($invoice->paid_at)->toBeNull();
    });

    it('rejects selling more than the available stock', function () {
        $this->post(route('pos.product.store'), posPayload([
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 999, 'unit_price' => 10, 'discount_pct' => 0],
            ],
        ]))->assertSessionHasErrors('lines');

        expect(ProductInvoice::count())->toBe(0);
        expect($this->product->refresh()->current_stock)->toBe(100);
    });

    it('computes VAT from the branch override rate', function () {
        $this->branch->update(['vat_rate_override' => 10.00]);

        $this->post(route('pos.product.store'), posPayload());

        $invoice = ProductInvoice::firstOrFail();
        expect((float) $invoice->vat_pct)->toBe(10.00)
            ->and((float) $invoice->vat_amount)->toBe(3.00)
            ->and((float) $invoice->total_amount)->toBe(33.00);
    });

    it('increments the invoice sequence per branch', function () {
        $this->post(route('pos.product.store'), posPayload());
        $this->post(route('pos.product.store'), posPayload());

        $numbers = ProductInvoice::orderBy('id')->pluck('invoice_number')->all();
        expect($numbers)->toBe([
            sprintf('INV-%03d-%05d', $this->branch->id, 1),
            sprintf('INV-%03d-%05d', $this->branch->id, 2),
        ]);
    });

    it('requires at least one line', function () {
        $this->post(route('pos.product.store'), posPayload(['lines' => []]))
            ->assertSessionHasErrors('lines');
    });

    it('creates a walk-in customer from name and phone', function () {
        $this->post(route('pos.product.store'), posPayload([
            'walkin_name' => 'زائر الناسخ',
            'walkin_phone' => '0500000001',
        ]));

        $this->assertDatabaseHas('customers', [
            'full_name' => 'زائر الناسخ',
            'phone' => '0500000001',
            'branch_id' => $this->branch->id,
            'customer_type' => 'individual',
        ]);

        $invoice = ProductInvoice::firstOrFail();
        expect($invoice->customer_id)->not->toBeNull();
    });

    it('reuses an existing customer with the same phone for a walk-in', function () {
        $existing = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0500000002',
        ]);

        $this->post(route('pos.product.store'), posPayload([
            'walkin_name' => 'اسم مختلف',
            'walkin_phone' => '0500000002',
        ]));

        expect(Customer::where('phone', '0500000002')->count())->toBe(1);
        expect(ProductInvoice::firstOrFail()->customer_id)->toBe($existing->id);
    });

    it('saves a manual line with no product and no stock movement', function () {
        $this->post(route('pos.product.store'), posPayload([
            'lines' => [
                ['product_id' => null, 'name' => 'خدمة تغليف', 'qty' => 2, 'unit_price' => 5, 'discount_pct' => 0],
            ],
        ]));

        $invoice = ProductInvoice::firstOrFail();
        expect((float) $invoice->subtotal)->toBe(10.00)
            ->and($invoice->lines)->toHaveCount(1)
            ->and($invoice->lines->first()->product_id)->toBeNull()
            ->and($invoice->lines->first()->product_name)->toBe('خدمة تغليف');

        $this->assertDatabaseCount('stock_movements', 1); // only the opening stock seeded
    });

    it('requires a name for a manual line', function () {
        $this->post(route('pos.product.store'), posPayload([
            'lines' => [
                ['product_id' => null, 'qty' => 1, 'unit_price' => 5, 'discount_pct' => 0],
            ],
        ]))->assertSessionHasErrors('lines.0.name');
    });

    it('applies a percentage coupon to the taxable base', function () {
        $coupon = Coupon::factory()->create([
            'branch_id' => $this->branch->id,
            'code' => 'save10',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'is_active' => true,
            'used_count' => 0,
        ]);

        $this->post(route('pos.product.store'), posPayload(['coupon_code' => 'SAVE10']));

        $invoice = ProductInvoice::firstOrFail();
        // subtotal 30, coupon 3, taxable 27, VAT 15% = 4.05, total 31.05
        expect((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->coupon_discount)->toBe(3.00)
            ->and((float) $invoice->vat_amount)->toBe(4.05)
            ->and((float) $invoice->total_amount)->toBe(31.05)
            ->and($invoice->coupon_id)->toBe($coupon->id);

        expect($coupon->refresh()->used_count)->toBe(1);
    });

    it('rejects an invalid coupon code', function () {
        $this->post(route('pos.product.store'), posPayload(['coupon_code' => 'NOPE']))
            ->assertSessionHasErrors('coupon_code');

        expect(ProductInvoice::count())->toBe(0);
    });

    it('applies an agent discount to the taxable base', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        $agent->agentProfile->update(['discount_mode' => 'discount', 'rate' => 10]);

        $this->post(route('pos.product.store'), posPayload(['agent_id' => $agent->id]));

        $invoice = ProductInvoice::firstOrFail();
        // subtotal 30, agent discount 10% = 3, taxable 27, VAT 15% = 4.05, total 31.05
        expect((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->agent_discount)->toBe(3.00)
            ->and((float) $invoice->agent_rebate)->toBe(0.00)
            ->and((float) $invoice->total_amount)->toBe(31.05)
            ->and($invoice->agent_id)->toBe($agent->id);
    });

    it('records an agent rebate without deducting it from the total', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        $agent->agentProfile->update(['discount_mode' => 'rebate', 'rate' => 10]);

        $this->post(route('pos.product.store'), posPayload(['agent_id' => $agent->id]));

        $invoice = ProductInvoice::firstOrFail();
        // subtotal 30, taxable 30, VAT 15% = 4.50, total 34.50. The rebate is
        // earned net of VAT: 30 / 1.15 = 26.09, at 10% = 2.61.
        expect((float) $invoice->total_amount)->toBe(34.50)
            ->and((float) $invoice->agent_discount)->toBe(0.00)
            ->and((float) $invoice->agent_rebate)->toBe(2.61);
    });

    it('applies a fixed agent discount to the taxable base', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        $agent->agentProfile->update(['discount_mode' => 'discount', 'discount_type' => 'fixed', 'rate' => 5]);

        $this->post(route('pos.product.store'), posPayload(['agent_id' => $agent->id]));

        $invoice = ProductInvoice::firstOrFail();
        // subtotal 30, fixed agent discount 5, taxable 25, VAT 15% = 3.75, total 28.75
        expect((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->agent_discount)->toBe(5.00)
            ->and((float) $invoice->agent_rebate)->toBe(0.00)
            ->and((float) $invoice->total_amount)->toBe(28.75)
            ->and($invoice->agent_id)->toBe($agent->id);
    });

    it('records a fixed agent rebate without deducting it from the total', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        $agent->agentProfile->update(['discount_mode' => 'rebate', 'discount_type' => 'fixed', 'rate' => 8]);

        $this->post(route('pos.product.store'), posPayload(['agent_id' => $agent->id]));

        $invoice = ProductInvoice::firstOrFail();
        // subtotal 30, taxable 30, VAT 15% = 4.50, total 34.50, fixed rebate 8
        expect((float) $invoice->total_amount)->toBe(34.50)
            ->and((float) $invoice->agent_discount)->toBe(0.00)
            ->and((float) $invoice->agent_rebate)->toBe(8.00);
    });

    it('rejects an agent from another branch', function () {
        $otherBranch = Branch::factory()->create();
        $agent = Agent::factory()->create(['branch_id' => $otherBranch->id]);

        $this->post(route('pos.product.store'), posPayload(['agent_id' => $agent->id]))
            ->assertSessionHasErrors('agent_id');

        expect(ProductInvoice::count())->toBe(0);
    });

    it('redirects to the print page when print is requested', function () {
        $this->post(route('pos.product.store'), posPayload(['print' => true]));

        $invoice = ProductInvoice::firstOrFail();

        $this->get(route('pos.product.print', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('pos/product/print'));
    });
});
