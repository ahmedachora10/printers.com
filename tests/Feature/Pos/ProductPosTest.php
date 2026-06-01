<?php

use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
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
});
