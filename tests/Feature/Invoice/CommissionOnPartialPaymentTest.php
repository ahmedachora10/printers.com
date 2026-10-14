<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceHelper;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * تاسك 182 — مثال العميل: فاتورة 1150، خامات 200، عمولة 300، ضريبة 150. الحدّ
 * 650: دفعةٌ جزئية تُبقي المحصَّل دونه تُرفض، وبلوغه يكتب العمولة الآن.
 */
function floorInvoice(float $lineCommission = 300, float $helperCommission = 0): ServiceInvoice
{
    $invoice = ServiceInvoice::create([
        'invoice_number' => 'SINV-FLR-'.fake()->unique()->numerify('#####'),
        'branch_id' => test()->branch->id,
        'user_id' => test()->employee->id,
        'subtotal' => 1000,
        'vat_pct' => 15,
        'vat_amount' => 150,
        'total_amount' => 1150,
        'employee_commission' => $lineCommission,
        'status' => InvoiceStatusEnum::DUE->value,
    ]);

    $invoice->lines()->create([
        'service_name' => 'لوحة',
        'qty' => 1,
        'unit_price' => 1000,
        'discount_pct' => 0,
        'subtotal' => 1000,
        'commission_pct' => 30,
        'commission_amount' => $lineCommission,
        'materials_cost' => 200,
        'materials_total' => 200,
        'is_tahazir' => false,
    ]);

    if ($helperCommission > 0) {
        $invoice->helpers()->create([
            'user_id' => test()->helper->id,
            'branch_service_id' => test()->service->id,
            'service_name' => 'لوحة',
            'share_type' => 'amount',
            'share_value' => 100,
            'share_net' => 100,
            'commission_pct' => 50,
            'commission_amount' => $helperCommission,
            'is_tahazir' => false,
        ]);
    }

    return $invoice;
}

function floorPay(ServiceInvoice $invoice, float $amount, ?string $paidAt = null): TestResponse
{
    return test()->actingAs(test()->accountant)->post(
        route('invoices.payments.store', ['type' => 'service', 'id' => $invoice->id]),
        array_filter(['amount' => $amount, 'payment_method_id' => test()->cash->id, 'paid_at' => $paidAt]),
    );
}

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

    $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->employee->addRole(Roles::EMPLOYEE->value);

    $this->helper = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->helper->addRole(Roles::EMPLOYEE->value);

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->addRole(Roles::ACCOUNTANT->value);

    $this->branchAdmin = User::factory()->create();
    $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
    $this->branch->update(['owner_id' => $this->branchAdmin->id]);

    $this->cash = PaymentMethod::factory()->create(['is_active' => true, 'requires_attachment' => false]);

    // BranchService is a Pivot: create() does not hand the id back — refetch.
    $template = ServiceTemplate::factory()->create();
    BranchService::create([
        'branch_id' => $this->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 0,
        'is_tahazir' => false,
        'is_active' => true,
    ]);
    $this->service = BranchService::where('service_template_id', $template->id)->firstOrFail();
});

it('refuses a partial payment below materials + commission + VAT, then earns the commission at the floor once', function () {
    $invoice = floorInvoice();
    expect($invoice->commissionFloor())->toBe(650.0)
        ->and($invoice->minimumPayment())->toBe(650.0);

    floorPay($invoice, 300)->assertSessionHasErrors('amount');
    expect($invoice->payments()->count())->toBe(0)
        ->and($invoice->refresh()->status)->toBe(InvoiceStatusEnum::DUE);

    $depositAt = now()->subDays(3)->startOfDay()->addHours(9);
    floorPay($invoice, 650, $depositAt->toDateTimeString())->assertSessionHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatusEnum::PARTIALLY_PAID)
        ->and($invoice->commission_earned_at->toDateTimeString())->toBe($depositAt->toDateTimeString())
        ->and($invoice->minimumPayment())->toBeNull()
        ->and((float) CommissionLedger::sole()->amount)->toBe(300.0)
        ->and(CommissionLedger::sole()->earned_at->toDateTimeString())->toBe($depositAt->toDateTimeString());

    // أيّ مبلغ بعد بلوغ الحدّ، والدفعة المُكمِلة لا تكتب العمولة ثانيةً.
    floorPay($invoice, 100)->assertSessionHasNoErrors();
    floorPay($invoice, 400)->assertSessionHasNoErrors();

    expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::PAID)
        ->and(CommissionLedger::count())->toBe(1);
});

it('always accepts a single full payment', function () {
    $invoice = floorInvoice();

    floorPay($invoice, 1150)->assertSessionHasNoErrors();

    expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::PAID)
        ->and((float) CommissionLedger::sole()->amount)->toBe(300.0);
});

it('counts the helpers\' share in the floor and writes their row at the floor', function () {
    $invoice = floorInvoice(lineCommission: 250, helperCommission: 50);

    floorPay($invoice, 649.99)->assertSessionHasErrors('amount');
    floorPay($invoice, 650)->assertSessionHasNoErrors();

    expect(CommissionLedger::where('invoice_line_type', ServiceInvoiceHelper::class)->sole()->user_id)->toBe($this->helper->id)
        ->and(round((float) CommissionLedger::sum('amount'), 2))->toBe(300.0);
});

it('reverses an early-earned commission to exactly zero when the partially paid invoice is returned', function () {
    $invoice = floorInvoice();
    floorPay($invoice, 650)->assertSessionHasNoErrors();

    returnServiceInvoice($invoice->refresh())->assertSessionHasNoErrors();

    // المرتجع يعكس نسبةً (650/1150) ثم يعكس ما بقي — لا أكثر، وإلا خصمه صرفُ العمولات من الموظف.
    expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::RETURNED)
        ->and(round((float) CommissionLedger::sum('amount'), 2))->toBe(0.0);
});

it('backfills commission for partially paid invoices that already reached the floor, dated by the crossing payment', function () {
    $reached = floorInvoice();
    $reached->update(['status' => InvoiceStatusEnum::PARTIALLY_PAID]);
    $crossing = now()->subMonth()->startOfDay()->addDays(9);
    foreach ([[100, $crossing->subDays(8)], [600, $crossing]] as [$amount, $at]) {
        $reached->payments()->create(['branch_id' => $this->branch->id, 'amount' => $amount, 'paid_at' => $at, 'recorded_by' => $this->accountant->id]);
    }

    $below = floorInvoice();
    $below->update(['status' => InvoiceStatusEnum::PARTIALLY_PAID]);
    $below->payments()->create(['branch_id' => $this->branch->id, 'amount' => 100, 'paid_at' => now(), 'recorded_by' => $this->accountant->id]);

    $this->artisan('commissions:backfill-partial', ['--dry-run' => true])->assertSuccessful();
    expect(CommissionLedger::count())->toBe(0);

    $this->artisan('commissions:backfill-partial')->assertSuccessful();
    $this->artisan('commissions:backfill-partial')->assertSuccessful();

    expect((float) CommissionLedger::sole()->amount)->toBe(300.0)
        ->and(CommissionLedger::sole()->earned_at->toDateTimeString())->toBe($crossing->toDateTimeString())
        ->and($reached->refresh()->commission_earned_at)->not->toBeNull()
        ->and($below->refresh()->commission_earned_at)->toBeNull();
});
