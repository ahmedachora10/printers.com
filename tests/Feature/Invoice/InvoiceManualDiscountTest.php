<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * خصمٌ إضافي على فاتورة لم يكتمل سدادها. مثال العميل حرفياً: فاتورة 200 (شاملة
 * الضريبة)، عربون 100، ثم اتُّفق على 180 فدفع 80 — فتُغلق مدفوعة على 180.
 */
function discountDueInvoice(array $extra = []): ServiceInvoice
{
    test()->actingAs(test()->employee)->post(route('pos.service.store'), array_merge([
        'status' => 'due',
        'payment_method_id' => paymentMethodId(),
        'lines' => [['branch_service_id' => test()->service->id, 'qty' => 1, 'unit_price' => 200, 'discount_pct' => 0]],
    ], $extra))->assertSessionHasNoErrors();

    test()->actingAs(test()->accountant);

    return ServiceInvoice::latest('id')->firstOrFail();
}

function discountPay(ServiceInvoice|ProductInvoice $invoice, array $payload): TestResponse
{
    return test()->post(
        route('invoices.payments.store', ['type' => $invoice instanceof ServiceInvoice ? 'service' : 'product', 'id' => $invoice->id]),
        array_merge(['payment_method_id' => paymentMethodId()], $payload),
    );
}

function discountOnly(ServiceInvoice|ProductInvoice $invoice, float $amount): TestResponse
{
    return test()->post(
        route('invoices.discount.store', ['type' => $invoice instanceof ServiceInvoice ? 'service' : 'product', 'id' => $invoice->id]),
        ['amount' => $amount],
    );
}

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

    $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->employee->addRole(Roles::EMPLOYEE->value);

    $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->accountant->addRole(Roles::ACCOUNTANT->value);

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
    UserService::create(['user_id' => $this->employee->id, 'branch_service_id' => $this->service->id, 'commission_override_pct' => 10]);
});

it('closes the client example: 200, deposit 100, then 80 paid with a 20 discount', function () {
    $invoice = discountDueInvoice();
    expect((float) $invoice->total_amount)->toBe(200.0)
        ->and((float) $invoice->employee_commission)->toBe(17.39); // 10% of 173.91

    discountPay($invoice, ['amount' => 100])->assertSessionHasNoErrors();
    discountPay($invoice, ['amount' => 80, 'discount' => 20])->assertSessionHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatusEnum::PAID)
        ->and((float) $invoice->manual_discount)->toBe(20.0)
        ->and((float) $invoice->total_amount)->toBe(180.0)
        ->and((float) $invoice->vat_amount)->toBe(23.48) // 180 − round(180 / 1.15)
        ->and($invoice->paidAmount())->toBe(180.0)
        // العمولة على الصافي الجديد 156.52 لا على 173.91.
        ->and((float) $invoice->employee_commission)->toBe(15.65)
        ->and((float) $invoice->lines()->first()->commission_amount)->toBe(15.65)
        ->and((float) CommissionLedger::sole()->amount)->toBe(15.65);
});

it('closes the invoice from the discount button when what was collected already covers it', function () {
    $invoice = discountDueInvoice();
    discountPay($invoice, ['amount' => 100, 'paid_at' => now()->subDays(2)->toDateTimeString()]);
    discountPay($invoice, ['amount' => 80, 'paid_at' => now()->subDay()->toDateTimeString()]);
    expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::PARTIALLY_PAID);

    discountOnly($invoice, 20)->assertSessionHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatusEnum::PAID)
        ->and((float) $invoice->total_amount)->toBe(180.0)
        ->and($invoice->payments()->count())->toBe(2)
        // المال وصل مع آخر دفعة، لا لحظة الخصم.
        ->and($invoice->paid_at->toDateString())->toBe(now()->subDay()->toDateString())
        ->and(CommissionLedger::count())->toBe(1);
});

it('accepts a percentage-sized discount and a negative correction, staying open', function () {
    $invoice = discountDueInvoice();
    discountPay($invoice, ['amount' => 100]);

    discountOnly($invoice, 30)->assertSessionHasNoErrors();
    discountOnly($invoice, -10)->assertSessionHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatusEnum::PARTIALLY_PAID)
        ->and((float) $invoice->manual_discount)->toBe(20.0)
        ->and((float) $invoice->total_amount)->toBe(180.0)
        ->and($invoice->remainingAmount())->toBe(80.0)
        ->and(CommissionLedger::count())->toBe(0);
});

it('refuses a discount that would drop the total below what was collected', function () {
    $invoice = discountDueInvoice();
    discountPay($invoice, ['amount' => 150]);

    discountOnly($invoice, 60)->assertSessionHasErrors('discount');
    discountOnly($invoice, -5)->assertSessionHasErrors('discount'); // لا خصم قائم يُصحَّح

    expect((float) $invoice->refresh()->total_amount)->toBe(200.0)
        ->and((float) $invoice->manual_discount)->toBe(0.0);
});

it('keeps paid invoices and employees out', function () {
    $invoice = discountDueInvoice();
    discountPay($invoice, ['amount' => 200]);
    discountOnly($invoice, 10)->assertForbidden();

    $open = discountDueInvoice();
    $this->actingAs($this->employee);
    discountOnly($open, 10)->assertForbidden();
});

it('recomputes an agent rebate on the new net', function () {
    $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
    setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'rebate', 'discount_type' => 'percentage', 'rate' => 10]);

    $invoice = discountDueInvoice(['agent_ids' => [$agent->id]]);
    expect((float) $invoice->invoiceAgents()->sole()->rebate_amount)->toBe(17.39);

    discountOnly($invoice, 20)->assertSessionHasNoErrors();

    expect((float) $invoice->invoiceAgents()->sole()->rebate_amount)->toBe(15.65);
});

it('keeps the discount when the due invoice is edited again from the POS', function () {
    $invoice = discountDueInvoice();
    discountOnly($invoice, 20)->assertSessionHasNoErrors();

    $this->actingAs($this->employee)->put(route('pos.service.update', $invoice), [
        'payment_method_id' => paymentMethodId(),
        'lines' => [['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 200, 'discount_pct' => 0]],
    ])->assertSessionHasNoErrors();

    $invoice->refresh();
    expect((float) $invoice->manual_discount)->toBe(20.0)
        ->and((float) $invoice->total_amount)->toBe(180.0)
        ->and((float) $invoice->employee_commission)->toBe(15.65);
});

it('discounts a product invoice the same way', function () {
    $invoice = ProductInvoice::create([
        'invoice_number' => 'INV-DISC-1',
        'branch_id' => $this->branch->id,
        'user_id' => $this->accountant->id,
        'subtotal' => 200,
        'vat_pct' => 15,
        'vat_amount' => 26.09,
        'total_amount' => 200,
        'status' => InvoiceStatusEnum::DUE->value,
    ]);
    $this->actingAs($this->accountant);

    discountPay($invoice, ['amount' => 100]);
    discountPay($invoice, ['amount' => 80, 'discount' => 20])->assertSessionHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatusEnum::PAID)
        ->and((float) $invoice->total_amount)->toBe(180.0)
        ->and((float) $invoice->vat_amount)->toBe(23.48)
        ->and((float) $invoice->manual_discount)->toBe(20.0);
});
