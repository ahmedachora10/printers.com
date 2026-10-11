<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceHelper;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * المساعدون في الفاتورة — مثال العميل: شغلة بـ60 ريال (شاملة الضريبة)، ساعد عارف
 * بـ20. حصّة عارف تُقتطع من أساس صاحب الفاتورة، وعارف يأخذ نسبته هو على حصّته.
 */

function helperService(Branch $branch, array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create([
        'branch_id' => $branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 0,
        'max_discount_pct' => 50,
        'is_tahazir' => false,
        'is_active' => true,
        ...$attrs,
    ]);

    // Pivot: create() لا يُرجع المعرّف.
    return BranchService::where('branch_id', $branch->id)->where('service_template_id', $template->id)->firstOrFail();
}

function helperRate(User $user, BranchService $service, float $pct): void
{
    UserService::create(['user_id' => $user->id, 'branch_service_id' => $service->id, 'commission_override_pct' => $pct]);
}

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

    $this->branchAdmin = User::factory()->create();
    $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
    $this->branch->update(['owner_id' => $this->branchAdmin->id]);

    $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->employee->addRole(Roles::EMPLOYEE->value);

    $this->arif = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'عارف']);
    $this->arif->addRole(Roles::EMPLOYEE->value);

    $this->service = helperService($this->branch);
    // خدمة المساعد ليست في الفاتورة — يكفي أنها من خدمات الفرع.
    $this->helpService = helperService($this->branch);

    helperRate($this->employee, $this->service, 10);
    helperRate($this->arif, $this->helpService, 10);

    $this->payload = fn (array $helpers) => [
        'payment_method_id' => paymentMethodId(),
        'status' => 'due',
        'lines' => [['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 60, 'discount_pct' => 0]],
        'helpers' => $helpers,
    ];
});

it('carves a SAR helper share out of the owner base and pays the helper on it', function () {
    $this->actingAs($this->employee)
        ->post(route('pos.service.store'), ($this->payload)([
            ['user_id' => $this->arif->id, 'branch_service_id' => $this->helpService->id, 'share_type' => 'amount', 'share_value' => 20],
        ]))
        ->assertSessionHasNoErrors();

    $invoice = ServiceInvoice::firstOrFail();
    $helper = ServiceInvoiceHelper::firstOrFail();

    // 60 / 1.15 = 52.17 صافٍ. حصة عارف 20 / 1.15 = 17.39 → 1.74.
    // صاحب الفاتورة على الباقي 34.78 → 3.48.
    expect((float) $helper->share_net)->toBe(17.39)
        ->and((float) $helper->commission_amount)->toBe(1.74)
        ->and((float) $invoice->employee_commission)->toBe(3.48)
        ->and(CommissionLedger::count())->toBe(0); // لا عمولة قبل الاعتماد

    $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));

    expect((float) CommissionLedger::where('user_id', $this->arif->id)->sum('amount'))->toBe(1.74)
        ->and((float) CommissionLedger::where('user_id', $this->employee->id)->sum('amount'))->toBe(3.48);
});

it('reads a percentage share off the invoice total', function () {
    $this->actingAs($this->employee)
        ->post(route('pos.service.store'), ($this->payload)([
            ['user_id' => $this->arif->id, 'branch_service_id' => $this->helpService->id, 'share_type' => 'percentage', 'share_value' => 25],
        ]))
        ->assertSessionHasNoErrors();

    // 25% من 52.17 = 13.04 → عارف 1.30؛ الباقي 39.13 → صاحبها 3.91.
    expect((float) ServiceInvoiceHelper::firstOrFail()->share_net)->toBe(13.04)
        ->and((float) ServiceInvoiceHelper::firstOrFail()->commission_amount)->toBe(1.30)
        ->and((float) ServiceInvoice::firstOrFail()->employee_commission)->toBe(3.91);
});

it('records a helper on a zero-commission service and still carves the share out', function () {
    $wire = helperService($this->branch); // «السلك» — لا نسبة لعارف عليه

    $this->actingAs($this->employee)
        ->post(route('pos.service.store'), ($this->payload)([
            ['user_id' => $this->arif->id, 'branch_service_id' => $wire->id, 'share_type' => 'amount', 'share_value' => 20],
        ]))
        ->assertSessionHasNoErrors();

    $invoice = ServiceInvoice::firstOrFail();
    $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));

    expect((float) ServiceInvoiceHelper::firstOrFail()->commission_amount)->toBe(0.00)
        ->and(CommissionLedger::where('user_id', $this->arif->id)->count())->toBe(1)
        ->and((float) $invoice->refresh()->employee_commission)->toBe(3.48);
});

it('caps the helper shares at the net after materials', function () {
    $this->service->update(['has_materials' => true, 'materials_cost' => 10]);

    // 52.17 − 10 خامات = 42.17؛ و50 / 1.15 = 43.48 تتجاوزه.
    $this->actingAs($this->employee)
        ->post(route('pos.service.store'), ($this->payload)([
            ['user_id' => $this->arif->id, 'branch_service_id' => $this->helpService->id, 'share_type' => 'amount', 'share_value' => 50],
        ]))
        ->assertSessionHasErrors('helpers');

    expect(ServiceInvoice::count())->toBe(0);
});

it('rejects the owner as their own helper and a helper from another branch', function (string $who) {
    $helper = $who === 'owner'
        ? $this->employee
        : User::factory()->create(['branch_id' => Branch::factory()->create()->id]);

    $this->actingAs($this->employee)
        ->post(route('pos.service.store'), ($this->payload)([
            ['user_id' => $helper->id, 'branch_service_id' => $this->helpService->id, 'share_type' => 'amount', 'share_value' => 10],
        ]))
        ->assertSessionHasErrors('helpers');
})->with(['owner', 'other branch']);

it('reverses the helper commission when the invoice is returned', function () {
    $this->actingAs($this->employee)->post(route('pos.service.store'), ($this->payload)([
        ['user_id' => $this->arif->id, 'branch_service_id' => $this->helpService->id, 'share_type' => 'amount', 'share_value' => 20],
    ]));

    $invoice = ServiceInvoice::firstOrFail();
    $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));

    returnServiceInvoice($invoice->refresh(), ['reason' => 'العميل ألغى الطلب'])->assertSessionHasNoErrors();

    expect((float) CommissionLedger::where('user_id', $this->arif->id)->sum('amount'))->toBe(0.00);
});

it('lists the helper row in the commission report under its own source', function () {
    $this->actingAs($this->employee)->post(route('pos.service.store'), ($this->payload)([
        ['user_id' => $this->arif->id, 'branch_service_id' => $this->helpService->id, 'share_type' => 'amount', 'share_value' => 20],
    ]));
    $invoice = ServiceInvoice::firstOrFail();
    $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));

    $this->actingAs($this->branchAdmin)
        ->get(route('reports.commissions', ['employee' => $this->arif->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('lines', 1)
            ->where('lines.0.invoiceNumber', $invoice->invoice_number)
            ->where('lines.0.serviceName', $this->helpService->serviceTemplate->name)
            ->where('lines.0.sourceLabel', 'عمولة مساعدة')
            ->where('lines.0.amount', 1.74)
        );
});

it('keeps helpers through an edit and rewrites their shares', function () {
    $this->actingAs($this->employee)->post(route('pos.service.store'), ($this->payload)([
        ['user_id' => $this->arif->id, 'branch_service_id' => $this->helpService->id, 'share_type' => 'amount', 'share_value' => 20],
    ]));
    $invoice = ServiceInvoice::firstOrFail();

    $this->actingAs($this->branchAdmin)
        ->put(route('pos.service.update', $invoice), ($this->payload)([
            ['user_id' => $this->arif->id, 'branch_service_id' => $this->helpService->id, 'share_type' => 'amount', 'share_value' => 30],
        ]))
        ->assertSessionHasNoErrors();

    // 30 / 1.15 = 26.09 → 2.61؛ الباقي 26.08 → 2.61.
    expect(ServiceInvoiceHelper::count())->toBe(1)
        ->and((float) ServiceInvoiceHelper::firstOrFail()->commission_amount)->toBe(2.61)
        ->and((float) $invoice->refresh()->employee_commission)->toBe(2.61);
});
