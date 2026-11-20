<?php

use App\Actions\InvoicePayment\RecordInvoicePaymentAction;
use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\BranchServiceMaterial;
use App\Models\PaymentMethod;
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

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->branchAdmin = User::factory()->create();
    $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
    $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id, 'vat_rate_override' => 15]);
    $this->branchAdmin->update(['branch_id' => $this->branch->id]);

    $this->employee = User::factory()->create(['branch_id' => $this->branch->id, 'base_commission_pct' => 30]);
    $this->employee->addRole(Roles::EMPLOYEE->value);

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->addRole(Roles::ACCOUNTANT->value);

    $this->cash = PaymentMethod::factory()->create(['is_active' => true, 'requires_attachment' => false]);

    $this->product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'category_id' => ProductCategory::factory()->create()->id,
        'unit_id' => ProductUnit::factory()->create()->id,
        'name' => 'ورق A4',
        'cost_price' => 15,
        'selling_price' => 23,
        'is_active' => true,
    ]);

    StockMovement::factory()->create([
        'product_id' => $this->product->id,
        'branch_id' => $this->branch->id,
        'type' => StockMovementTypeEnum::OPENING_STOCK,
        'qty' => 10,
        'created_by' => $this->branchAdmin->id,
    ]);
});

function sellAsService(float $pct = 10): BranchService
{
    test()->actingAs(test()->branchAdmin)
        ->post(route('inventory.products.sell-as-service', test()->product), ['commission_pct' => $pct])
        ->assertSessionHasNoErrors();

    return BranchService::where('product_id', test()->product->id)->firstOrFail();
}

/** فاتورة آجلة من الموظف: سطر المنتج، ومعه سطر خدمة عادية خامتُها المنتج نفسه إن طُلب. */
function productInvoice(BranchService $service, int $qty = 2, ?BranchService $ordinary = null): ServiceInvoice
{
    $lines = [['branch_service_id' => $service->id, 'qty' => $qty, 'unit_price' => 23, 'discount_pct' => 0]];

    if ($ordinary) {
        $lines[] = ['branch_service_id' => $ordinary->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0];
    }

    test()->actingAs(test()->employee)
        ->post(route('pos.service.store'), ['payment_method_id' => test()->cash->id, 'status' => 'due', 'lines' => $lines])
        ->assertSessionHasNoErrors();

    return ServiceInvoice::latest('id')->firstOrFail();
}

function payProductInvoice(ServiceInvoice $invoice, float $amount): void
{
    test()->actingAs(test()->accountant)->post(
        route('invoices.payments.store', ['type' => 'service', 'id' => $invoice->id]),
        ['amount' => $amount, 'payment_method_id' => test()->cash->id, 'confirm_materials_shortage' => 1],
    )->assertSessionHasNoErrors();
}

it('creates one linked service mirroring the product, with the product as its x1 material', function () {
    $service = sellAsService(10);

    expect($service->serviceTemplate->name)->toBe('ورق A4')
        ->and($service->serviceTemplate->branch_id)->toBe($this->branch->id)
        ->and((float) $service->price_per_sqm)->toBe(23.0)
        ->and((float) $service->materials_cost)->toBe(15.0)
        ->and($service->has_materials)->toBeTrue()
        ->and((float) $service->base_commission_pct)->toBe(10.0);

    $material = BranchServiceMaterial::where('branch_service_id', $service->id)->sole();
    expect($material->product_id)->toBe($this->product->id)
        ->and((float) $material->qty_per_unit)->toBe(1.0);

    // النسبة المُدخلة لا عمولة الموظف الأساسية (30).
    expect((float) UserService::where('branch_service_id', $service->id)->where('user_id', $this->employee->id)->value('commission_override_pct'))
        ->toBe(10.0);

    // ضغطةٌ ثانية لا تنشئ خدمةً ثانية.
    sellAsService(10);
    expect(BranchService::where('product_id', $this->product->id)->count())->toBe(1);
});

it('sends the product category to the POS so the products tab can group by it', function () {
    $service = sellAsService();

    $this->actingAs($this->employee)
        ->get(route('pos.service.create'))
        ->assertInertia(fn ($page) => $page->where('services', fn ($services) => collect($services)
            ->firstWhere('id', $service->id)['productCategory'] === $this->product->category->name));
});

it('follows the product on every save and deactivates on delete', function () {
    $service = sellAsService();

    $tiers = [['to' => 9, 'price' => 30, 'min_price' => null, 'max_price' => null], ['to' => null, 'price' => 26, 'min_price' => 25, 'max_price' => null]];
    $this->product->update(['name' => 'ورق A3', 'selling_price' => 30, 'cost_price' => 18, 'price_tiers' => $tiers]);
    $service->refresh();
    expect($service->serviceTemplate->name)->toBe('ورق A3')
        ->and((float) $service->price_per_sqm)->toBe(30.0)
        ->and((float) $service->materials_cost)->toBe(18.0)
        ->and($service->price_tiers)->toBe($tiers);

    $this->actingAs($this->branchAdmin)->patch(route('inventory.products.toggle-status', $this->product));
    expect($service->refresh()->is_active)->toBeFalse();

    $this->product->refresh()->update(['is_active' => true]);
    expect($service->refresh()->is_active)->toBeTrue();

    $this->product->delete();
    expect($service->refresh()->is_active)->toBeFalse();
});

it('draws the product at the first payment and ordinary materials only at full payment', function () {
    $service = sellAsService();

    $vinyl = Product::factory()->create(['branch_id' => $this->branch->id, 'cost_price' => 1]);
    StockMovement::factory()->create([
        'product_id' => $vinyl->id, 'branch_id' => $this->branch->id,
        'type' => StockMovementTypeEnum::OPENING_STOCK, 'qty' => 50, 'created_by' => $this->branchAdmin->id,
    ]);
    $template = ServiceTemplate::factory()->create();
    BranchService::create(['branch_id' => $this->branch->id, 'service_template_id' => $template->id, 'base_commission_pct' => 0, 'max_discount_pct' => 0, 'is_tahazir' => false, 'is_active' => true]);
    $ordinary = BranchService::where('service_template_id', $template->id)->firstOrFail();
    BranchServiceMaterial::create(['branch_service_id' => $ordinary->id, 'product_id' => $vinyl->id, 'qty_per_unit' => 3, 'waste_pct' => 0]);

    $invoice = productInvoice($service, 2, $ordinary);
    expect($this->product->refresh()->current_stock)->toEqual(10.0);

    payProductInvoice($invoice, 60);
    expect($this->product->refresh()->current_stock)->toEqual(8.0)
        ->and($vinyl->refresh()->current_stock)->toEqual(50.0);

    payProductInvoice($invoice, 86);
    expect($invoice->refresh()->status->value)->toBe('paid')
        ->and($this->product->refresh()->current_stock)->toEqual(8.0)
        ->and($vinyl->refresh()->current_stock)->toEqual(47.0);
});

it('puts the product back when a correction brings collected to zero, and draws again on the next payment', function () {
    $invoice = productInvoice(sellAsService());

    payProductInvoice($invoice, 40);
    expect($this->product->refresh()->current_stock)->toEqual(8.0);

    // التصحيح السالب لا يمرّ من النموذج — من الـ Action مباشرةً.
    $this->actingAs($this->accountant);
    app(RecordInvoicePaymentAction::class)->handle($invoice->refresh(), ['amount' => -40, 'payment_method_id' => $this->cash->id], $this->accountant);
    expect($this->product->refresh()->current_stock)->toEqual(10.0);

    payProductInvoice($invoice, 40);
    expect($this->product->refresh()->current_stock)->toEqual(8.0);
});

it('stops selling as a service and converts back to the same service with the new rate', function () {
    $service = sellAsService(10);
    $invoice = productInvoice($service);

    $this->actingAs($this->branchAdmin)
        ->delete(route('inventory.products.stop-selling-as-service', $this->product))
        ->assertSessionHasNoErrors();

    expect($service->refresh()->is_active)->toBeFalse();

    // حفظ المنتج أو تفعيله لا يعيدها إلى شاشة الموظف.
    $this->product->refresh()->update(['selling_price' => 25, 'is_active' => true]);
    expect($service->refresh()->is_active)->toBeFalse();

    // الفاتورة المفتوحة تكمل، ويُسحب المخزون عند الدفع.
    payProductInvoice($invoice, 40);
    expect($this->product->refresh()->current_stock)->toEqual(8.0);

    $this->actingAs($this->branchAdmin)->get(route('inventory.products.index'))
        ->assertInertia(fn ($page) => $page->where('items.data.0.soldAsService', false)->where('items.data.0.serviceCommissionPct', 10));

    $templates = ServiceTemplate::count();
    expect(sellAsService(12)->id)->toBe($service->id);

    $service->refresh();
    expect($service->is_active)->toBeTrue()
        ->and($service->product_sale_stopped_at)->toBeNull()
        ->and((float) $service->price_per_sqm)->toBe(25.0)
        ->and((float) $service->base_commission_pct)->toBe(12.0)
        ->and(ServiceTemplate::count())->toBe($templates)
        ->and((float) UserService::where('branch_service_id', $service->id)->where('user_id', $this->employee->id)->value('commission_override_pct'))->toBe(12.0);
});

it('returns the product to stock when an invoice with a deposit is returned', function () {
    $invoice = productInvoice(sellAsService());

    payProductInvoice($invoice, 40);
    expect($this->product->refresh()->current_stock)->toEqual(8.0);

    returnServiceInvoice($invoice, ['reason' => 'ألغى العميل', 'payment_method_id' => $this->cash->id])->assertSessionHasNoErrors();

    expect($invoice->refresh()->status->value)->toBe('returned')
        ->and($this->product->refresh()->current_stock)->toEqual(10.0);
});
