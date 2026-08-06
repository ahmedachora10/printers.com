<?php

use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\ProductUnit;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Invoice-level note (ملاحظات للعميل) on both invoice types: it is saved from
 * the POS, survives a re-edit, comes back on every screen that prints it, and
 * is capped at 1000 characters.
 */
function notesBranchService(int $branchId): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create([
        'branch_id' => $branchId,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'is_tahazir' => false,
        'is_active' => true,
    ]);

    // BranchService is a Pivot (non-incrementing), so create() doesn't hydrate
    // the id — re-fetch so callers get a model with its primary key populated.
    return BranchService::where('branch_id', $branchId)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

describe('Invoice-level notes', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->service = notesBranchService($this->branch->id);

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

    it('saves the note on a service invoice', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'notes' => '  التسليم بعد 3 أيام عمل  ',
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 2, 'unit_price' => 50, 'discount_pct' => 0],
                ],
            ])
            ->assertRedirect();

        // Trimmed on the way in, exactly as the per-line detail is.
        expect(ServiceInvoice::firstOrFail()->notes)->toBe('التسليم بعد 3 أيام عمل');
    });

    it('saves the note on a product invoice', function () {
        $this->actingAs($this->accountant)
            ->post(route('pos.product.store'), [
                'status' => 'paid',
                'notes' => 'الأسعار شاملة التوصيل داخل المدينة',
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])
            ->assertRedirect();

        expect(ProductInvoice::firstOrFail()->notes)->toBe('الأسعار شاملة التوصيل داخل المدينة');
    });

    it('stores a blank note as null rather than an empty string', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'notes' => '   ',
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])
            ->assertRedirect();

        expect(ServiceInvoice::firstOrFail()->notes)->toBeNull();
    });

    it('rejects a note longer than 1000 characters on both invoice types', function () {
        $tooLong = str_repeat('ط', 1001);

        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'notes' => $tooLong,
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])
            ->assertSessionHasErrors('notes');

        $this->actingAs($this->accountant)
            ->post(route('pos.product.store'), [
                'status' => 'paid',
                'notes' => $tooLong,
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])
            ->assertSessionHasErrors('notes');

        expect(ServiceInvoice::count())->toBe(0)
            ->and(ProductInvoice::count())->toBe(0);
    });

    it('loads the saved note back into the POS edit screen and updates it', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'notes' => 'الملاحظة الأصلية',
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
                ],
            ])
            ->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        $this->actingAs($this->employee)
            ->get(route('pos.service.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos/service/index')
                ->where('invoice.notes', 'الملاحظة الأصلية'));

        $this->actingAs($this->employee)
            ->put(route('pos.service.update', $invoice), [
                'notes' => 'الملاحظة المعدّلة',
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
                ],
            ])
            ->assertRedirect();

        expect($invoice->refresh()->notes)->toBe('الملاحظة المعدّلة');
    });

    it('clears the note when the edit submits an empty box', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'notes' => 'ملاحظة ستُحذف',
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
                ],
            ])
            ->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        $this->actingAs($this->employee)
            ->put(route('pos.service.update', $invoice), [
                'notes' => null,
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
                ],
            ])
            ->assertRedirect();

        expect($invoice->refresh()->notes)->toBeNull();
    });

    it('carries the note to the service POS print sheet and the invoice viewer', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'notes' => 'ملاحظة الطباعة',
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
                ],
            ])
            ->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        $this->actingAs($this->employee)
            ->get(route('pos.service.print', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos/service/print')
                ->where('invoice.notes', 'ملاحظة الطباعة'));

        $this->actingAs($this->employee)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/show')
                ->where('invoice.notes', 'ملاحظة الطباعة'));

        $this->actingAs($this->employee)
            ->get(route('invoices.print', ['type' => 'service', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/print')
                ->where('invoice.notes', 'ملاحظة الطباعة'));
    });

    it('carries the note to the product POS print sheet and the invoice viewer', function () {
        $this->actingAs($this->accountant)
            ->post(route('pos.product.store'), [
                'status' => 'paid',
                'notes' => 'ملاحظة فاتورة المنتجات',
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])
            ->assertRedirect();

        $invoice = ProductInvoice::firstOrFail();

        $this->actingAs($this->accountant)
            ->get(route('pos.product.print', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos/product/print')
                ->where('invoice.notes', 'ملاحظة فاتورة المنتجات'));

        $this->actingAs($this->accountant)
            ->get(route('invoices.show', ['type' => 'product', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/show')
                ->where('invoice.notes', 'ملاحظة فاتورة المنتجات'));

        $this->actingAs($this->accountant)
            ->get(route('invoices.print', ['type' => 'product', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/print')
                ->where('invoice.notes', 'ملاحظة فاتورة المنتجات'));
    });
});
