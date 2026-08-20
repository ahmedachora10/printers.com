<?php

use App\Enums\Roles;
use App\Enums\ServicePricingTypeEnum;
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

/** خدمة فرع، بالقطعة أو بالمتر المربع. */
function stockService(array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create(array_merge([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 50,
        'max_discount_pct' => 50,
        'is_tahazir' => false,
        'has_materials' => false,
        'materials_cost' => 0,
        'is_active' => true,
    ], $attrs));

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

/** منتج في مخزون الفرع برصيد افتتاحي. */
function stockProduct(float $openingStock, array $attrs = []): Product
{
    $product = Product::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'category_id' => test()->category->id,
        'unit_id' => test()->unit->id,
        'cost_price' => 4.00,
        'selling_price' => 10.00,
        'min_stock_level' => 0,
        'current_stock' => 0,
    ], $attrs));

    StockMovement::factory()->create([
        'product_id' => $product->id,
        'branch_id' => test()->branch->id,
        'type' => StockMovementTypeEnum::OPENING_STOCK,
        'qty' => $openingStock,
        'created_by' => test()->branchAdmin->id,
    ]);

    return $product->refresh();
}

/** ربط منتج بخدمة كخامة تُستهلك بكمية محدّدة لكل وحدة. */
function linkMaterial(BranchService $service, Product $product, float $qtyPerUnit): BranchServiceMaterial
{
    return BranchServiceMaterial::create([
        'branch_service_id' => $service->id,
        'product_id' => $product->id,
        'qty_per_unit' => $qtyPerUnit,
    ]);
}

/** فاتورة خدمة آجلة من سطر واحد. */
function stockPayload(BranchService $service, array $lineAttrs = [], array $overrides = []): array
{
    return array_merge([
        'status' => 'due',
        'lines' => [array_merge([
            'branch_service_id' => $service->id,
            'qty' => 1,
            'unit_price' => 100,
            'discount_pct' => 0,
        ], $lineAttrs)],
    ], $overrides);
}

function approveStockInvoice(ServiceInvoice $invoice): void
{
    test()->actingAs(test()->branchAdmin)
        ->patch(route('invoices.service.pay', payable($invoice)))
        ->assertRedirect();

    test()->actingAs(test()->employee);
}

describe('Service materials drawn from stock (تاسك 50 + شقّ المخزون من 54)', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        // Branch admins are linked through branches.owner_id, not users.branch_id.
        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->category = ProductCategory::factory()->create();
        $this->unit = ProductUnit::factory()->create();

        $this->actingAs($this->employee);
    });

    // ---- الخصم عند الاعتماد لا عند الإنشاء --------------------------------

    it('draws nothing while the invoice is still due', function () {
        $service = stockService();
        $product = stockProduct(100);
        linkMaterial($service, $product, 2);

        $this->post(route('pos.service.store'), stockPayload($service, ['qty' => 3]))->assertRedirect();

        expect($product->refresh()->current_stock)->toEqual(100.0)
            ->and(StockMovement::where('type', StockMovementTypeEnum::SALE_OUT)->count())->toBe(0);
    });

    it('draws qty_per_unit × line qty out of stock when the invoice is approved', function () {
        $service = stockService();
        $product = stockProduct(100);
        linkMaterial($service, $product, 2);

        $this->post(route('pos.service.store'), stockPayload($service, ['qty' => 3]))->assertRedirect();
        approveStockInvoice(ServiceInvoice::firstOrFail());

        // 2 لكل قطعة × 3 قطع = 6
        expect($product->refresh()->current_stock)->toEqual(94.0);

        $movement = StockMovement::where('type', StockMovementTypeEnum::SALE_OUT)->firstOrFail();

        expect((float) $movement->qty)->toEqual(-6.0)
            ->and($movement->reference_type)->toBe(ServiceInvoice::class)
            ->and($movement->reference_id)->toBe(ServiceInvoice::firstOrFail()->id);
    });

    it('draws every material the service defines', function () {
        $service = stockService();
        $vinyl = stockProduct(100);
        $ink = stockProduct(50);
        linkMaterial($service, $vinyl, 2);
        linkMaterial($service, $ink, 0.5);

        $this->post(route('pos.service.store'), stockPayload($service, ['qty' => 4]))->assertRedirect();
        approveStockInvoice(ServiceInvoice::firstOrFail());

        expect($vinyl->refresh()->current_stock)->toEqual(92.0)
            ->and($ink->refresh()->current_stock)->toEqual(48.0);
    });

    it('moves no stock for a service with no materials defined', function () {
        $service = stockService(['has_materials' => true, 'materials_cost' => 20]);
        $product = stockProduct(100);

        $this->post(route('pos.service.store'), stockPayload($service))->assertRedirect();
        approveStockInvoice(ServiceInvoice::firstOrFail());

        expect($product->refresh()->current_stock)->toEqual(100.0)
            ->and(StockMovement::where('type', StockMovementTypeEnum::SALE_OUT)->count())->toBe(0);
    });

    it('draws the materials immediately for an invoice created already paid', function () {
        $service = stockService();
        $product = stockProduct(100);
        linkMaterial($service, $product, 2);

        // مدير الفرع وحده يُنشئ فاتورة مدفوعة — الموظف مقيَّد بالآجلة (تاسك M12).
        UserService::create([
            'user_id' => $this->branchAdmin->id,
            'branch_service_id' => $service->id,
            'commission_override_pct' => 50,
        ]);

        $this->actingAs($this->branchAdmin)
            ->post(route('pos.service.store'), stockPayload($service, ['qty' => 2], ['status' => 'paid']))
            ->assertRedirect();

        expect($product->refresh()->current_stock)->toEqual(96.0);
    });

    // ---- الخدمة المسعّرة بالمتر المربع تستهلك بالمساحة --------------------

    it('consumes a sqm service by area, not by piece count', function () {
        $service = stockService([
            'pricing_type' => ServicePricingTypeEnum::Sqm,
            'price_per_sqm' => 50,
        ]);
        $vinyl = stockProduct(100, ['is_sqm' => true]);
        linkMaterial($service, $vinyl, 1); // متر فينيل لكل متر مبيع

        $this->post(route('pos.service.store'), stockPayload($service, [
            'qty' => 2,
            'unit_price' => 0,
            'width_cm' => 100,
            'height_cm' => 50,
        ]))->assertRedirect();

        approveStockInvoice(ServiceInvoice::firstOrFail());

        // (100/100) × (50/100) = 0.5 م² للقطعة × قطعتين = 1 م²
        expect($vinyl->refresh()->current_stock)->toEqual(99.0);
    });

    // ---- الإرجاع يعيد ما خُصم ---------------------------------------------

    it('puts the materials back when the invoice is returned', function () {
        $service = stockService();
        $product = stockProduct(100);
        linkMaterial($service, $product, 2);

        $this->post(route('pos.service.store'), stockPayload($service, ['qty' => 3]))->assertRedirect();
        $invoice = ServiceInvoice::firstOrFail();
        approveStockInvoice($invoice);

        expect($product->refresh()->current_stock)->toEqual(94.0);

        // الاسترجاع صلاحية الموظف صاحب الفاتورة وحده (ServiceInvoicePolicy).
        $this->actingAs($this->employee)
            ->post(route('pos.service.return', $invoice), ['reason' => 'اختبار'])
            ->assertRedirect();

        expect($product->refresh()->current_stock)->toEqual(100.0);

        // الحركتان محفوظتان — السجلّ إدراج فقط ولا يُصحَّح بالحذف.
        expect(StockMovement::where('reference_type', ServiceInvoice::class)->count())->toBe(2)
            ->and(StockMovement::where('type', StockMovementTypeEnum::RETURN_IN)->count())->toBe(1);
    });

    it('returns exactly what was drawn even if the recipe changed since', function () {
        $service = stockService();
        $product = stockProduct(100);
        $material = linkMaterial($service, $product, 2);

        $this->post(route('pos.service.store'), stockPayload($service, ['qty' => 3]))->assertRedirect();
        $invoice = ServiceInvoice::firstOrFail();
        approveStockInvoice($invoice);

        // تغيّر تعريف الخامة بعد البيع — الإرجاع يقرأ الحركات لا التعريف.
        $material->update(['qty_per_unit' => 10]);

        // الاسترجاع صلاحية الموظف صاحب الفاتورة وحده (ServiceInvoicePolicy).
        $this->actingAs($this->employee)
            ->post(route('pos.service.return', $invoice), ['reason' => 'اختبار'])
            ->assertRedirect();

        expect($product->refresh()->current_stock)->toEqual(100.0);
    });

    it('moves no stock when a never-approved invoice is returned', function () {
        $service = stockService();
        $product = stockProduct(100);
        linkMaterial($service, $product, 2);

        $this->post(route('pos.service.store'), stockPayload($service, ['qty' => 3]))->assertRedirect();
        $invoice = ServiceInvoice::firstOrFail();

        // الاسترجاع صلاحية الموظف صاحب الفاتورة وحده (ServiceInvoicePolicy).
        $this->actingAs($this->employee)
            ->post(route('pos.service.return', $invoice), ['reason' => 'اختبار'])
            ->assertRedirect();

        expect($product->refresh()->current_stock)->toEqual(100.0)
            ->and(StockMovement::where('reference_type', ServiceInvoice::class)->count())->toBe(0);
    });

    // ---- الرقم المحاسبي مستقلّ عن حركة المخزون ---------------------------

    it('leaves the accounting materials cost alone — it never doubles', function () {
        $service = stockService(['has_materials' => true, 'materials_cost' => 20]);
        $product = stockProduct(100);
        linkMaterial($service, $product, 2);

        $this->post(route('pos.service.store'), stockPayload($service, ['qty' => 2]))->assertRedirect();
        $invoice = ServiceInvoice::firstOrFail();
        approveStockInvoice($invoice);

        $line = $invoice->lines()->firstOrFail();

        // المبلغ كما هو من تعريف الخدمة، وحركة المخزون منفصلة عنه تماماً.
        expect((float) $line->materials_cost)->toEqual(20.00)
            ->and((float) $line->materials_total)->toEqual(40.00)
            ->and($product->refresh()->current_stock)->toEqual(96.0);
    });

    // ---- شاشة تعريف الخامات ------------------------------------------------

    it('lets the branch admin set a service’s materials', function () {
        $service = stockService();
        $product = stockProduct(100);

        $this->actingAs($this->branchAdmin)
            ->put(route('branch-services.materials.update', $service), [
                'materials' => [['product_id' => $product->id, 'qty_per_unit' => 2.5]],
            ])
            ->assertRedirect();

        expect($service->materials()->count())->toBe(1)
            ->and((float) $service->materials()->firstOrFail()->qty_per_unit)->toEqual(2.5);
    });

    it('removes a material left out of the submitted list', function () {
        $service = stockService();
        $product = stockProduct(100);
        linkMaterial($service, $product, 2);

        $this->actingAs($this->branchAdmin)
            ->put(route('branch-services.materials.update', $service), ['materials' => []])
            ->assertRedirect();

        expect($service->materials()->count())->toBe(0);
    });

    it('refuses a material belonging to another branch', function () {
        $service = stockService();
        $otherBranch = Branch::factory()->create();
        $foreign = Product::factory()->create([
            'branch_id' => $otherBranch->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
        ]);

        $this->actingAs($this->branchAdmin)
            ->put(route('branch-services.materials.update', $service), [
                'materials' => [['product_id' => $foreign->id, 'qty_per_unit' => 1]],
            ])
            ->assertSessionHasErrors('materials.0.product_id');

        expect($service->materials()->count())->toBe(0);
    });

    it('forbids an employee from editing a service’s materials', function () {
        $service = stockService();
        $product = stockProduct(100);

        $this->put(route('branch-services.materials.update', $service), [
            'materials' => [['product_id' => $product->id, 'qty_per_unit' => 1]],
        ])->assertForbidden();
    });
});
