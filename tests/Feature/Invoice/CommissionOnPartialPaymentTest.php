<?php

use App\Actions\ServiceInvoice\MarkServiceInvoicePaidAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceHelper;
use App\Models\ServiceInvoiceLine;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * تاسكا 191 + 192 — مثال العميل: فاتورة 100 (ضريبة 13.04)، عمولة 10، خامات 30.
 * أقلّ دفعة = الخامات (30)، والعمولة تُكتب بنسبة المحصَّل مع كل دفعة وبتاريخها.
 */
function floorInvoice(float $lineCommission = 10, float $helperCommission = 0, float $materials = 30): ServiceInvoice
{
    $invoice = ServiceInvoice::create([
        'invoice_number' => 'SINV-FLR-'.fake()->unique()->numerify('#####'),
        'branch_id' => test()->branch->id,
        'user_id' => test()->employee->id,
        'subtotal' => 86.96,
        'vat_pct' => 15,
        'vat_amount' => 13.04,
        'total_amount' => 100,
        'employee_commission' => $lineCommission,
        'status' => InvoiceStatusEnum::DUE->value,
    ]);

    $invoice->lines()->create([
        'service_name' => 'لوحة',
        'qty' => 1,
        'unit_price' => 86.96,
        'discount_pct' => 0,
        'subtotal' => 86.96,
        'commission_pct' => 10,
        'commission_amount' => $lineCommission,
        'materials_cost' => $materials,
        'materials_total' => $materials,
        'is_tahazir' => false,
    ]);

    if ($helperCommission > 0) {
        $invoice->helpers()->create([
            'user_id' => test()->helper->id,
            'branch_service_id' => test()->service->id,
            'service_name' => 'لوحة',
            'share_type' => 'amount',
            'share_value' => 20,
            'share_net' => 20,
            'commission_pct' => 10,
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

function floorRefund(ServiceInvoice $invoice, float $amount): void
{
    test()->actingAs(test()->branchAdmin)->post(route('refunds.store'), [
        'source_type' => 'service',
        'invoice_id' => $invoice->id,
        'payment_method_id' => test()->cash->id,
        'amount' => $amount,
        'reason' => 'اختبار',
    ])->assertSessionHasNoErrors();
}

function ledgerTotal(?string $type = null): float
{
    return round((float) CommissionLedger::query()->when($type, fn ($q) => $q->where('invoice_line_type', $type))->sum('amount'), 2);
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

it('earns the commission payment by payment, each dated by its payment, summing to exactly the full commission', function () {
    $invoice = floorInvoice();
    $first = now()->subMonth()->startOfDay()->addHours(9);
    $second = now()->subDays(3)->startOfDay()->addHours(9);

    floorPay($invoice, 40, $first->toDateTimeString())->assertSessionHasNoErrors();
    expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::PARTIALLY_PAID)
        ->and(ledgerTotal())->toBe(4.0)
        ->and(CommissionLedger::sole()->earned_at->toDateTimeString())->toBe($first->toDateTimeString());

    floorPay($invoice, 30, $second->toDateTimeString())->assertSessionHasNoErrors();
    expect(ledgerTotal())->toBe(7.0)
        ->and(CommissionLedger::latest('id')->first()->earned_at->toDateTimeString())->toBe($second->toDateTimeString());

    floorPay($invoice, 30)->assertSessionHasNoErrors();
    expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::PAID)
        ->and(ledgerTotal())->toBe(10.0)
        ->and(CommissionLedger::pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([4.0, 3.0, 3.0]);

    // لا تكرار: إعادة المزامنة لا تكتب شيئاً.
    app(MarkServiceInvoicePaidAction::class)->syncCommissionLedger($invoice->refresh(), now());
    expect(CommissionLedger::count())->toBe(3);
});

it('lands on the exact commission with no rounding residue', function () {
    $invoice = floorInvoice(lineCommission: 10.01);

    foreach ([33.33, 33.33, 33.34] as $amount) {
        floorPay($invoice, $amount)->assertSessionHasNoErrors();
    }

    expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::PAID)
        ->and(ledgerTotal())->toBe(10.01);
});

it('refuses a partial payment below the materials cost alone, and accepts any amount after it', function () {
    $invoice = floorInvoice();
    expect($invoice->paymentFloor())->toBe(30.0)
        ->and($invoice->minimumPayment())->toBe(30.0);

    floorPay($invoice, 29.99)->assertSessionHasErrors(['amount' => 'أقلّ مبلغ مقبول للدفعة: 30.00 ر.س — يغطّي تكلفة الخامات.']);
    expect($invoice->payments()->count())->toBe(0);

    floorPay($invoice, 30)->assertSessionHasNoErrors();
    expect($invoice->refresh()->minimumPayment())->toBeNull();

    floorPay($invoice, 1)->assertSessionHasNoErrors();
    expect(ledgerTotal())->toBe(3.1);
});

it('has no floor without materials, and always accepts a single full payment', function () {
    $noMaterials = floorInvoice(materials: 0);
    expect($noMaterials->minimumPayment())->toBeNull();
    floorPay($noMaterials, 5)->assertSessionHasNoErrors();

    $full = floorInvoice();
    floorPay($full, 100)->assertSessionHasNoErrors();

    expect($full->refresh()->status)->toBe(InvoiceStatusEnum::PAID)
        ->and((float) CommissionLedger::where('invoice_line_id', $full->lines()->value('id'))->sole()->amount)->toBe(10.0);
});

it('scales the helpers\' commission by the same share', function () {
    $invoice = floorInvoice(lineCommission: 8, helperCommission: 2);

    floorPay($invoice, 40)->assertSessionHasNoErrors();

    expect(ledgerTotal(ServiceInvoiceLine::class))->toBe(3.2)
        ->and(ledgerTotal(ServiceInvoiceHelper::class))->toBe(0.8)
        ->and(CommissionLedger::where('invoice_line_type', ServiceInvoiceHelper::class)->sole()->user_id)->toBe($this->helper->id);
});

it('never reverses commission already paid out, and earns again only once the share passes it', function () {
    $invoice = floorInvoice();
    floorPay($invoice, 40)->assertSessionHasNoErrors();
    CommissionLedger::query()->update(['paid_at' => now()]);

    // مرتجع 20 ⇒ نسبة المحصَّل 2، لكن الـ4 صُرفت — لا تُستردّ.
    floorRefund($invoice, 20);
    expect(ledgerTotal())->toBe(4.0);

    // المحصَّل الصافي 50 ⇒ 5: يُضاف الفرق عمّا صُرف وحده.
    floorPay($invoice, 30)->assertSessionHasNoErrors();
    expect(ledgerTotal())->toBe(5.0);
});

it('brings the commission down with a partial refund, and to zero on a full return', function () {
    $invoice = floorInvoice();
    floorPay($invoice, 40)->assertSessionHasNoErrors();

    floorRefund($invoice, 10);
    expect(ledgerTotal())->toBe(3.0);

    returnServiceInvoice($invoice->refresh())->assertSessionHasNoErrors();
    expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::RETURNED)
        ->and(ledgerTotal())->toBe(0.0);
});

it('rebases commission written in full on partially paid invoices, unpaid commission only', function () {
    $lastPayment = now()->subDays(2)->startOfDay();

    $make = function (bool $paidOut) use ($lastPayment) {
        $invoice = floorInvoice();
        $invoice->update(['status' => InvoiceStatusEnum::PARTIALLY_PAID, 'commission_earned_at' => now()->subWeek()]);
        $invoice->payments()->create(['branch_id' => $this->branch->id, 'amount' => 40, 'paid_at' => $lastPayment, 'recorded_by' => $this->accountant->id]);
        CommissionLedger::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'invoice_line_id' => $invoice->lines()->value('id'),
            'invoice_line_type' => ServiceInvoiceLine::class,
            'amount' => 10,
            'is_tahazir' => false,
            'source_type' => 'standard',
            'earned_at' => now()->subWeek(),
            'paid_at' => $paidOut ? now() : null,
        ]);

        return $invoice;
    };
    $unpaid = $make(false);
    $paidOut = $make(true);

    $this->artisan('commissions:rebase-partial', ['--dry-run' => true])->assertSuccessful();
    expect(ledgerTotal())->toBe(20.0);

    $this->artisan('commissions:rebase-partial')->assertSuccessful();
    $this->artisan('commissions:rebase-partial')->assertSuccessful();

    $sumFor = fn (ServiceInvoice $i) => round((float) $i->ledgerEntries()->sum('amount'), 2);
    expect($sumFor($unpaid))->toBe(4.0)
        ->and($sumFor($paidOut))->toBe(10.0)
        ->and(CommissionLedger::count())->toBe(3)
        ->and(CommissionLedger::latest('id')->first()->earned_at->toDateTimeString())->toBe($lastPayment->toDateTimeString());
});
