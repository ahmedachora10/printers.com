<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\QuotationStatusEnum;
use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\ProductUnit;
use App\Models\Quotation;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

    $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->employee->addRole(Roles::EMPLOYEE->value);

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->addRole(Roles::ACCOUNTANT->value);

    $this->branchAdmin = User::factory()->create();
    $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
    $this->branch->update(['owner_id' => $this->branchAdmin->id]);

    $template = ServiceTemplate::factory()->create();
    BranchService::create([
        'branch_id' => $this->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'is_tahazir' => false,
        'is_active' => true,
    ]);
    $this->service = BranchService::where('service_template_id', $template->id)->firstOrFail();

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
        'qty' => 5,
        'created_by' => $this->accountant->id,
    ]);
});

function quoteServiceLines(int $qty = 3): array
{
    return ['lines' => [['branch_service_id' => test()->service->id, 'qty' => $qty, 'unit_price' => 10, 'discount_pct' => 0]]];
}

function createServiceQuotation(array $extra = []): Quotation
{
    test()->actingAs(test()->employee)
        ->post(route('quotations.store', 'service'), [...quoteServiceLines(), 'walkin_name' => 'عميل', 'walkin_phone' => '0500000001', ...$extra])
        ->assertRedirect(route('quotations.index'));

    return Quotation::latest('id')->firstOrFail();
}

it('saves a service quotation with its own numbering and no financial effect', function () {
    $coupon = Coupon::factory()->create(['branch_id' => $this->branch->id, 'code' => 'Q10', 'discount_type' => 'fixed', 'discount_value' => 5, 'used_count' => 0]);

    $quotation = createServiceQuotation(['coupon_code' => 'Q10']);

    expect($quotation->quotation_number)->toBe(sprintf('QT-%03d-%05d', $this->branch->id, 1))
        ->and($quotation->status)->toBe(QuotationStatusEnum::NEW)
        ->and((float) $quotation->total_amount)->toBe(25.00)
        ->and($quotation->customer_id)->not->toBeNull()
        ->and(ServiceInvoice::count())->toBe(0)
        ->and(CommissionLedger::count())->toBe(0)
        ->and($coupon->fresh()->used_count)->toBe(0);
});

it('quotes products beyond stock without moving stock, sharing the branch sequence', function () {
    createServiceQuotation();

    $this->actingAs($this->accountant)
        ->post(route('quotations.store', 'product'), ['lines' => [['product_id' => $this->product->id, 'qty' => 50, 'unit_price' => 10, 'discount_pct' => 0]]])
        ->assertRedirect(route('quotations.index'));

    $quotation = Quotation::latest('id')->firstOrFail();

    expect($quotation->quotation_number)->toBe(sprintf('QT-%03d-%05d', $this->branch->id, 2))
        ->and((float) $quotation->total_amount)->toBe(500.00)
        ->and(StockMovement::count())->toBe(1)
        ->and(ProductInvoice::count())->toBe(0);
});

it('keeps quotations by type to the roles of that point of sale', function () {
    $this->actingAs($this->accountant)->post(route('quotations.store', 'service'), quoteServiceLines())->assertForbidden();
    $this->actingAs($this->employee)
        ->post(route('quotations.store', 'product'), ['lines' => [['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10]]])
        ->assertForbidden();
});

it('records the customer reply, with an optional rejection reason', function () {
    $quotation = createServiceQuotation();

    $this->post(route('quotations.send', $quotation))->assertSessionHasNoErrors();
    expect($quotation->fresh()->status)->toBe(QuotationStatusEnum::PENDING);

    $this->post(route('quotations.reject', $quotation))->assertSessionHasNoErrors();
    expect($quotation->fresh()->status)->toBe(QuotationStatusEnum::REJECTED)
        ->and($quotation->fresh()->rejection_reason)->toBeNull();

    // تعديل المرفوض يعيده جديداً ليُرسل من جديد — والموظف لا يعدّله، الإدارة تفعل.
    $this->put(route('quotations.update', $quotation), quoteServiceLines(4))->assertForbidden();
    $this->actingAs($this->branchAdmin)->put(route('quotations.update', $quotation), quoteServiceLines(4))->assertRedirect();

    expect($quotation->fresh()->status)->toBe(QuotationStatusEnum::NEW)
        ->and((float) $quotation->fresh()->total_amount)->toBe(40.00);
});

it('prints a quotation at once, without the branch tax number', function () {
    $this->branch->update(['tax_number' => '300000000000003']);
    $quotation = createServiceQuotation();

    $this->get(route('pos.service.quotation.print', $quotation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('pos/service/print')
            ->where('invoice.invoiceNumber', $quotation->quotation_number)
            ->where('invoice.status', 'quotation')
            ->where('branch.taxNumber', null));
});

it('hides other employees quotations', function () {
    $quotation = createServiceQuotation();
    $other = User::factory()->create(['branch_id' => $this->branch->id]);
    $other->addRole(Roles::EMPLOYEE->value);

    $this->actingAs($other)->get(route('quotations.index'))
        ->assertInertia(fn ($page) => $page->has('quotations.data', 0));
    $this->get(route('pos.service.quotation.print', $quotation))->assertForbidden();

    $this->actingAs($this->accountant)->get(route('quotations.index'))
        ->assertInertia(fn ($page) => $page->has('quotations.data', 1));
});

it('opens the point of sale prefilled from the quotation for conversion', function () {
    $quotation = createServiceQuotation();
    $this->post(route('quotations.accept', $quotation));

    $this->get(route('pos.service.quotation', ['quotation' => $quotation, 'convert' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('pos/service/index')
            ->where('invoice.id', null)
            ->where('invoice.lines.0.qty', 3)
            ->where('quotation.mode', 'convert'));
});

it('converts an approved quotation into a due invoice exactly once', function () {
    $quotation = createServiceQuotation();
    $payload = [...quoteServiceLines(), 'customer_id' => $quotation->customer_id, 'status' => 'due', 'quotation_id' => $quotation->id];

    // لم يوافق العميل بعد.
    $this->post(route('pos.service.store'), $payload)->assertForbidden();

    $this->post(route('quotations.accept', $quotation));
    $this->post(route('pos.service.store'), $payload)->assertRedirect();

    $invoice = ServiceInvoice::firstOrFail();
    $quotation->refresh();

    expect($invoice->status)->toBe(InvoiceStatusEnum::DUE)
        ->and($quotation->status)->toBe(QuotationStatusEnum::CONVERTED)
        ->and($quotation->invoice_id)->toBe($invoice->id)
        ->and($quotation->invoice_number)->toBe($invoice->invoice_number)
        ->and($invoice->quotation->id)->toBe($quotation->id);

    $this->post(route('pos.service.store'), $payload)->assertForbidden();
    expect(ServiceInvoice::count())->toBe(1);
});

it('converts a product quotation through the product point of sale', function () {
    $this->actingAs($this->accountant)
        ->post(route('quotations.store', 'product'), ['lines' => [['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 10]]]);
    $quotation = Quotation::firstOrFail();
    $this->post(route('quotations.accept', $quotation));

    $this->post(route('pos.product.store'), [
        'status' => 'paid',
        'payment_method_id' => paymentMethodId($this->branch->id),
        'quotation_id' => $quotation->id,
        'lines' => [['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 10]],
    ])->assertRedirect();

    expect($quotation->fresh()->status)->toBe(QuotationStatusEnum::CONVERTED)
        ->and(ProductInvoice::firstOrFail()->quotation->id)->toBe($quotation->id);
});

it('reopens both quotation types in their point of sale for editing', function () {
    $service = createServiceQuotation();
    $this->get(route('pos.service.quotation', $service))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('quotation.mode', 'edit')->where('invoice.lines.0.branchServiceId', $this->service->id));

    $this->actingAs($this->accountant)
        ->post(route('quotations.store', 'product'), ['lines' => [['product_id' => $this->product->id, 'qty' => 50, 'unit_price' => 10]]]);
    $product = Quotation::latest('id')->firstOrFail();

    $this->get(route('pos.product.quotation', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('pos/product/index')->where('invoice.lines.0.productId', $this->product->id));
    $this->get(route('pos.product.quotation.print', $product))->assertOk();
});
