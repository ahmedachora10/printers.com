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

function reportService(): BranchService
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

function reportProduct(float $openingStock, float $costPrice = 4.00): Product
{
    $product = Product::factory()->create([
        'branch_id' => test()->branch->id,
        'category_id' => test()->category->id,
        'unit_id' => test()->unit->id,
        'cost_price' => $costPrice,
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

/** فاتورة معتمَدة تستهلك خامتها من المخزون. */
function reportInvoice(BranchService $service, int $qty = 3): ServiceInvoice
{
    test()->actingAs(test()->employee)
        ->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $service->id,
                'qty' => $qty,
                'unit_price' => 100,
                'discount_pct' => 0,
            ]],
        ])
        ->assertRedirect();

    $invoice = ServiceInvoice::latest('id')->firstOrFail();

    test()->actingAs(test()->branchAdmin)
        ->patch(route('invoices.service.pay', payable($invoice)))
        ->assertRedirect();

    return $invoice->refresh();
}

describe('تقرير استهلاك الخامات', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->category = ProductCategory::factory()->create();
        $this->unit = ProductUnit::factory()->create();
    });

    it('totals the consumed quantity and its cost', function () {
        $service = reportService();
        $product = reportProduct(100, 4.00);
        BranchServiceMaterial::create([
            'branch_service_id' => $service->id,
            'product_id' => $product->id,
            'qty_per_unit' => 2,
        ]);

        reportInvoice($service, 3);

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.materials'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/materials/index')
                // 2 × 3 = 6 وحدات بسعر تكلفة 4.00
                // Inertia يشحن العدد الصحيح بلا كسر، وwhere يقارن بالهوية — فالمتوقَّع int.
                ->where('totals.netQty', 6)
                ->where('totals.netCost', 24)
                ->where('totals.productCount', 1)
                ->where('totals.invoiceCount', 1)
            );
    });

    it('nets the return out of the consumption', function () {
        $service = reportService();
        $product = reportProduct(100, 4.00);
        BranchServiceMaterial::create([
            'branch_service_id' => $service->id,
            'product_id' => $product->id,
            'qty_per_unit' => 2,
        ]);

        $invoice = reportInvoice($service, 3);

        $this->actingAs($this->employee)
            ->post(route('pos.service.return', $invoice), ['reason' => 'اختبار'])
            ->assertRedirect();

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.materials'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totals.netQty', 0)
                ->where('totals.netCost', 0)
            );
    });

    it('attributes the consumption to the service that drew it', function () {
        $service = reportService();
        $product = reportProduct(100);
        BranchServiceMaterial::create([
            'branch_service_id' => $service->id,
            'product_id' => $product->id,
            'qty_per_unit' => 2,
        ]);

        $invoice = reportInvoice($service, 2);
        $lineName = $invoice->lines()->firstOrFail()->service_name;

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.materials'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('byService.0.name', $lineName)
                ->where('byService.0.netQty', 4)
                ->where('byProduct.0.productId', $product->id)
            );
    });

    it('prices the consumption from the cost stored on the movement, not today’s', function () {
        $service = reportService();
        $product = reportProduct(100, 4.00);
        BranchServiceMaterial::create([
            'branch_service_id' => $service->id,
            'product_id' => $product->id,
            'qty_per_unit' => 1,
        ]);

        reportInvoice($service, 2);

        // سعر التكلفة تغيّر بعد البيع — التقرير يقرأ ما حُفظ على الحركة.
        $product->update(['cost_price' => 99.00]);

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.materials'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totals.netCost', 8));
    });

    it('keeps a branch admin out of another branch’s consumption', function () {
        $service = reportService();
        $product = reportProduct(100);
        BranchServiceMaterial::create([
            'branch_service_id' => $service->id,
            'product_id' => $product->id,
            'qty_per_unit' => 2,
        ]);

        reportInvoice($service, 3);

        $otherBranch = Branch::factory()->create();
        $otherAdmin = User::factory()->create();
        $otherAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $otherBranch->update(['owner_id' => $otherAdmin->id]);

        $this->actingAs($otherAdmin)
            ->get(route('reports.materials'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totals.netQty', 0));
    });

    it('forbids an employee from opening the report', function () {
        $this->actingAs($this->employee)
            ->get(route('reports.materials'))
            ->assertForbidden();
    });

    it('exports the movements as a spreadsheet', function () {
        $service = reportService();
        $product = reportProduct(100);
        BranchServiceMaterial::create([
            'branch_service_id' => $service->id,
            'product_id' => $product->id,
            'qty_per_unit' => 2,
        ]);

        reportInvoice($service, 3);

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.materials.export'))
            ->assertOk();
    });
});
