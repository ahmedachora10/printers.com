<?php

use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\BranchServiceMaterial;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** خدمة فرع بخامة واحدة من المخزون. */
function refundService(): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 50,
        'max_discount_pct' => 50,
        'is_tahazir' => false,
        'has_materials' => false,
        'materials_cost' => 0,
        'is_active' => true,
    ]);

    $service = BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();

    UserService::create([
        'user_id' => test()->employee->id,
        'branch_service_id' => $service->id,
        'commission_override_pct' => 50,
    ]);

    return $service;
}

function refundProduct(float $openingStock): Product
{
    $product = Product::factory()->create([
        'branch_id' => test()->branch->id,
        'category_id' => test()->category->id,
        'unit_id' => test()->unit->id,
        'cost_price' => 4.00,
        'selling_price' => 10.00,
        'min_stock_level' => 0,
        'current_stock' => 0,
    ]);

    StockMovement::factory()->create([
        'product_id' => $product->id,
        'branch_id' => test()->branch->id,
        'type' => StockMovementTypeEnum::OPENING_STOCK,
        'qty' => $openingStock,
        'created_by' => test()->branchAdmin->id,
    ]);

    return $product->refresh();
}

/** فاتورة خدمة معتمَدة، خُصمت خاماتها من المخزون. */
function approvedInvoiceWithMaterials(BranchService $service, int $qty = 3): ServiceInvoice
{
    test()->actingAs(test()->employee)
        ->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $service->id,
                'qty' => $qty,
                'unit_price' => 100,
                'discount_pct' => 0,
            ]],
        ])
        ->assertRedirect();

    $invoice = ServiceInvoice::firstOrFail();

    test()->actingAs(test()->branchAdmin)
        ->patch(route('invoices.service.pay', payable($invoice)))
        ->assertRedirect();

    return $invoice->refresh();
}

describe('مرتجع فاتورة الخدمة يعيد خاماتها إلى المخزون', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        // مدير الفرع مرتبط عبر branches.owner_id لا users.branch_id.
        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->category = ProductCategory::factory()->create();
        $this->unit = ProductUnit::factory()->create();
    });

    it('returns the materials to stock when the refund asks for it', function () {
        $service = refundService();
        $product = refundProduct(100);
        BranchServiceMaterial::create([
            'branch_service_id' => $service->id,
            'product_id' => $product->id,
            'qty_per_unit' => 2,
        ]);

        $invoice = approvedInvoiceWithMaterials($service);

        expect($product->refresh()->current_stock)->toEqual(94.0);

        $this->actingAs($this->branchAdmin)
            ->post(route('refunds.store'), [
                'source_type' => 'service',
                'invoice_id' => $invoice->id,
                'amount' => 50,
                'reason' => 'الطباعة معيبة',
                'reverse_stock' => true,
            ])
            ->assertRedirect();

        expect($product->refresh()->current_stock)->toEqual(100.0)
            ->and(StockMovement::where('type', StockMovementTypeEnum::RETURN_IN)->count())->toBe(1);
    });

    it('leaves the materials drawn when the refund does not ask', function () {
        $service = refundService();
        $product = refundProduct(100);
        BranchServiceMaterial::create([
            'branch_service_id' => $service->id,
            'product_id' => $product->id,
            'qty_per_unit' => 2,
        ]);

        $invoice = approvedInvoiceWithMaterials($service);

        $this->actingAs($this->branchAdmin)
            ->post(route('refunds.store'), [
                'source_type' => 'service',
                'invoice_id' => $invoice->id,
                'amount' => 50,
                'reason' => 'خصم تسوية',
                'reverse_stock' => false,
            ])
            ->assertRedirect();

        // الشغل نُفِّذ والخامة استُهلكت — ردُّ جزءٍ من المال لا يعيدها.
        expect($product->refresh()->current_stock)->toEqual(94.0)
            ->and(StockMovement::where('type', StockMovementTypeEnum::RETURN_IN)->count())->toBe(0);
    });

    it('returns the materials once, whatever the refunded fraction', function () {
        $service = refundService();
        $product = refundProduct(100);
        BranchServiceMaterial::create([
            'branch_service_id' => $service->id,
            'product_id' => $product->id,
            'qty_per_unit' => 2,
        ]);

        $invoice = approvedInvoiceWithMaterials($service);

        $this->actingAs($this->branchAdmin)
            ->post(route('refunds.store'), [
                'source_type' => 'service',
                'invoice_id' => $invoice->id,
                'amount' => 30,
                'reason' => 'مرتجع أول',
                'reverse_stock' => true,
            ])
            ->assertRedirect();

        // مرتجع ثانٍ يطلب عكس المخزون يُرفض — سبق عكسه.
        $this->actingAs($this->branchAdmin)
            ->post(route('refunds.store'), [
                'source_type' => 'service',
                'invoice_id' => $invoice->id,
                'amount' => 30,
                'reason' => 'مرتجع ثانٍ',
                'reverse_stock' => true,
            ])
            ->assertSessionHasErrors('reverse_stock');

        expect($product->refresh()->current_stock)->toEqual(100.0)
            ->and(StockMovement::where('type', StockMovementTypeEnum::RETURN_IN)->count())->toBe(1);
    });

    it('moves no stock for an invoice whose services define no materials', function () {
        $service = refundService();
        $product = refundProduct(100);

        $invoice = approvedInvoiceWithMaterials($service);

        $this->actingAs($this->branchAdmin)
            ->post(route('refunds.store'), [
                'source_type' => 'service',
                'invoice_id' => $invoice->id,
                'amount' => 50,
                'reason' => 'اختبار',
                'reverse_stock' => true,
            ])
            ->assertRedirect();

        expect($product->refresh()->current_stock)->toEqual(100.0)
            ->and(StockMovement::where('reference_type', ServiceInvoice::class)->count())->toBe(0);
    });

    it('tells the refund form that an approved invoice has materials to return', function () {
        $service = refundService();
        $product = refundProduct(100);
        BranchServiceMaterial::create([
            'branch_service_id' => $service->id,
            'product_id' => $product->id,
            'qty_per_unit' => 2,
        ]);

        $invoice = approvedInvoiceWithMaterials($service);

        $this->actingAs($this->branchAdmin)
            ->getJson(route('refunds.lookup', ['number' => $invoice->invoice_number]))
            ->assertOk()
            ->assertJsonPath('invoice.hasMaterials', true)
            ->assertJsonPath('invoice.stockReversed', false);
    });
});
