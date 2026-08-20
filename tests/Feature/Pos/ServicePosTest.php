<?php

use App\Enums\Roles;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use App\Notifications\DueInvoiceNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function makeBranchService(array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create(array_merge([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'is_tahazir' => false,
        'is_active' => true,
    ], $attrs));

    // BranchService is a Pivot (non-incrementing), so create() doesn't hydrate
    // the id — re-fetch so callers get a model with its primary key populated.
    return BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

// Commission now comes from a per-employee rate; with no row the employee earns
// 0% on the service (zero fallback).
function setCommissionRate(User $user, BranchService $service, float $pct): void
{
    UserService::create([
        'user_id' => $user->id,
        'branch_service_id' => $service->id,
        'commission_override_pct' => $pct,
    ]);
}

function svcPayload(array $overrides = []): array
{
    return array_merge([
        'status' => 'due',
        'lines' => [
            ['branch_service_id' => test()->service->id, 'qty' => 3, 'unit_price' => 10, 'discount_pct' => 0],
        ],
    ], $overrides);
}

// Approve (pay) a due invoice as the branch admin — the accountant's approval
// is what realises the employee's commission ledger. Restores the acting
// employee afterwards so the rest of the test continues as before.
function approveInvoice(ServiceInvoice $invoice): void
{
    test()->actingAs(test()->branchAdmin)
        ->patch(route('invoices.service.pay', payable($invoice)))
        ->assertRedirect();

    test()->actingAs(test()->employee);
}

describe('Service POS', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($this->employee);

        // Branch admins are linked through branches.owner_id, not users.branch_id.
        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->service = makeBranchService();

        // Both the employee and branch admin earn 10% on the default service.
        setCommissionRate($this->employee, $this->service, 10);
        setCommissionRate($this->branchAdmin, $this->service, 10);
    });

    it('lets an employee open the service POS screen', function () {
        $this->get(route('pos.service.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('pos/service/index'));
    });

    it('prevents an accountant from opening the service POS screen', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        $this->get(route('pos.service.create'))->assertForbidden();
    });

    it('creates a paid invoice with correct totals and commission', function () {
        $this->actingAs($this->branchAdmin);

        $this->post(route('pos.service.store'), svcPayload(['status' => 'paid']))
            ->assertRedirect(route('pos.service.create'))
            ->assertSessionHas('success');

        $invoice = ServiceInvoice::firstOrFail();

        expect($invoice->status->value)->toBe('paid')
            ->and($invoice->paid_at)->not->toBeNull()
            // الأسعار شاملة للضريبة: 30 مُدخلة = 30 يدفعها العميل،
            // صافيها 26.09 والضريبة المستخرجة 3.91.
            ->and((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->vat_amount)->toBe(3.91)
            ->and((float) $invoice->total_amount)->toBe(30.00)
            // Commission is earned net of VAT: 30 / 1.15 = 26.09, at 10% = 2.61.
            ->and((float) $invoice->employee_commission)->toBe(2.61)
            ->and($invoice->invoice_number)->toBe(sprintf('SINV-%03d-%05d', $this->branch->id, 1))
            ->and($invoice->lines)->toHaveCount(1);

        $line = $invoice->lines->first();
        expect((float) $line->commission_pct)->toBe(10.00)
            ->and((float) $line->commission_amount)->toBe(2.61);
    });

    it('does not write a commission ledger row until the invoice is approved', function () {
        $this->post(route('pos.service.store'), svcPayload());

        // The employee's due invoice earns nothing yet — the ledger stays empty
        // until an accountant approves (pays) it.
        expect(CommissionLedger::count())->toBe(0);

        approveInvoice(ServiceInvoice::firstOrFail());

        expect(CommissionLedger::count())->toBe(1);
    });

    it('writes one immutable commission ledger row per line once approved', function () {
        $this->post(route('pos.service.store'), svcPayload());

        $invoice = ServiceInvoice::firstOrFail();
        $line = $invoice->lines->first();

        approveInvoice($invoice);

        expect(CommissionLedger::count())->toBe(1);

        $entry = CommissionLedger::firstOrFail();
        expect($entry->user_id)->toBe($this->employee->id)
            ->and($entry->branch_id)->toBe($this->branch->id)
            ->and((float) $entry->amount)->toBe(2.61)
            ->and($entry->invoice_line_type)->toBe(ServiceInvoiceLine::class)
            ->and($entry->invoice_line_id)->toBe($line->id)
            ->and($entry->source_type->value)->toBe('standard')
            ->and($entry->is_tahazir)->toBeFalse()
            ->and($entry->paid_at)->toBeNull()
            ->and($entry->earned_at)->not->toBeNull();
    });

    it('flags tahazir commission on the ledger', function () {
        $tahazir = makeBranchService(['is_tahazir' => true, 'base_commission_pct' => 5]);
        setCommissionRate($this->employee, $tahazir, 5);

        $this->post(route('pos.service.store'), svcPayload([
            'lines' => [
                ['branch_service_id' => $tahazir->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
            ],
        ]));

        approveInvoice(ServiceInvoice::firstOrFail());

        $entry = CommissionLedger::firstOrFail();
        // 100 / 1.15 = 86.96, at 5% = 4.35
        expect($entry->is_tahazir)->toBeTrue()
            ->and((float) $entry->amount)->toBe(4.35);
    });

    // ── COMMISSION IS EARNED NET OF VAT ────────────────────────────

    it('earns the employee commission on the value net of VAT', function () {
        // The client's worked example: a 100 line at 15% VAT and a 50% rate.
        // 100 / 1.15 = 86.96, at 50% = 43.48 — not 50.00 on the gross.
        $service = makeBranchService(['base_commission_pct' => 50]);
        setCommissionRate($this->employee, $service, 50);

        $this->post(route('pos.service.store'), svcPayload([
            'lines' => [
                ['branch_service_id' => $service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
            ],
        ]));

        $invoice = ServiceInvoice::firstOrFail();
        approveInvoice($invoice);

        // السعر شامل الضريبة: 100 مُدخلة = 100 يدفعها العميل، صافيها 86.96
        // والضريبة المستخرجة 13.04 — والعمولة لم تتغيّر بحرف.
        expect((float) $invoice->refresh()->subtotal)->toBe(100.00)
            ->and((float) $invoice->vat_amount)->toBe(13.04)
            ->and((float) $invoice->total_amount)->toBe(100.00)
            ->and((float) $invoice->employee_commission)->toBe(43.48)
            ->and((float) $invoice->lines->first()->commission_amount)->toBe(43.48)
            ->and((float) CommissionLedger::firstOrFail()->amount)->toBe(43.48);
    });

    it('takes the coupon discount before dividing out VAT', function () {
        // Order matters: discounts first, then VAT, then commission.
        // 100 − 20 coupon = 80; 80 / 1.15 = 69.57; at 50% = 34.785 → 34.79.
        $service = makeBranchService(['base_commission_pct' => 50]);
        setCommissionRate($this->employee, $service, 50);

        Coupon::factory()->create([
            'branch_id' => $this->branch->id,
            'code' => 'VATORDER',
            'discount_type' => 'fixed',
            'discount_value' => 20,
            'is_active' => true,
        ]);

        $this->post(route('pos.service.store'), svcPayload([
            'coupon_code' => 'VATORDER',
            'lines' => [
                ['branch_service_id' => $service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
            ],
        ]));

        $invoice = ServiceInvoice::firstOrFail();

        expect((float) $invoice->coupon_discount)->toBe(20.00)
            // الإجمالي بعد الخصم شامل الضريبة أيضاً: 80 لا 92.
            ->and((float) $invoice->total_amount)->toBe(80.00)
            ->and((float) $invoice->vat_amount)->toBe(10.43)
            ->and((float) $invoice->employee_commission)->toBe(34.79);
    });

    it('leaves a zero-VAT branch commission on the gross value', function () {
        // With no VAT there is nothing to divide out — the guard against the
        // net-of-VAT rule quietly shrinking commissions where it should not.
        $this->branch->update(['vat_rate_override' => 0]);

        $service = makeBranchService(['base_commission_pct' => 50]);
        setCommissionRate($this->employee, $service, 50);

        $this->post(route('pos.service.store'), svcPayload([
            'lines' => [
                ['branch_service_id' => $service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
            ],
        ]));

        expect((float) ServiceInvoice::firstOrFail()->employee_commission)->toBe(50.00);
    });

    it('applies a line discount to the subtotal and commission', function () {
        $this->post(route('pos.service.store'), svcPayload([
            'lines' => [
                ['branch_service_id' => $this->service->id, 'qty' => 2, 'unit_price' => 10, 'discount_pct' => 10],
            ],
        ]));

        $invoice = ServiceInvoice::firstOrFail();
        // 2 * 10 * 0.9 = 18 subtotal; net of VAT 18 / 1.15 = 15.65, at 10% = 1.57
        expect((float) $invoice->subtotal)->toBe(18.00)
            ->and((float) $invoice->employee_commission)->toBe(1.57);
    });

    it('rejects a discount that exceeds the service ceiling', function () {
        $this->post(route('pos.service.store'), svcPayload([
            'lines' => [
                ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 50],
            ],
        ]))->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0)
            ->and(CommissionLedger::count())->toBe(0);
    });

    it('saves a due invoice with no paid_at', function () {
        $this->post(route('pos.service.store'), svcPayload(['status' => 'due']));

        $invoice = ServiceInvoice::firstOrFail();
        expect($invoice->status->value)->toBe('due')
            ->and($invoice->paid_at)->toBeNull();
    });

    it('forbids an employee from issuing a paid invoice', function () {
        $this->post(route('pos.service.store'), svcPayload(['status' => 'paid']))
            ->assertSessionHasErrors('status');

        expect(ServiceInvoice::count())->toBe(0)
            ->and(CommissionLedger::count())->toBe(0);
    });

    it('notifies the accountant and branch admin when an employee raises a due invoice', function () {
        Notification::fake();

        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        $this->post(route('pos.service.store'), svcPayload(['status' => 'due']))
            ->assertRedirect(route('pos.service.create'));

        Notification::assertSentTo([$accountant, $this->branchAdmin], DueInvoiceNotification::class);
        Notification::assertNotSentTo([$this->employee], DueInvoiceNotification::class);
    });

    it('computes VAT from the branch override rate', function () {
        $this->branch->update(['vat_rate_override' => 10.00]);

        $this->post(route('pos.service.store'), svcPayload());

        $invoice = ServiceInvoice::firstOrFail();
        // 30 شاملة ضريبة 10%: صافيها 27.27 والضريبة المستخرجة 2.73
        expect((float) $invoice->vat_pct)->toBe(10.00)
            ->and((float) $invoice->vat_amount)->toBe(2.73)
            ->and((float) $invoice->total_amount)->toBe(30.00);
    });

    it('increments the invoice sequence per branch', function () {
        $this->post(route('pos.service.store'), svcPayload());
        $this->post(route('pos.service.store'), svcPayload());

        $numbers = ServiceInvoice::orderBy('id')->pluck('invoice_number')->all();
        expect($numbers)->toBe([
            sprintf('SINV-%03d-%05d', $this->branch->id, 1),
            sprintf('SINV-%03d-%05d', $this->branch->id, 2),
        ]);
    });

    it('requires at least one line', function () {
        $this->post(route('pos.service.store'), svcPayload(['lines' => []]))
            ->assertSessionHasErrors('lines');
    });

    it('creates a walk-in customer from name and phone', function () {
        $this->post(route('pos.service.store'), svcPayload([
            'walkin_name' => 'زائر الناسخ',
            'walkin_phone' => '0500000001',
        ]));

        $this->assertDatabaseHas('customers', [
            'full_name' => 'زائر الناسخ',
            'phone' => '0500000001',
            'branch_id' => $this->branch->id,
            'customer_type' => 'individual',
        ]);

        expect(ServiceInvoice::firstOrFail()->customer_id)->not->toBeNull();
    });

    it('saves the walk-in tax number on the created customer', function () {
        $this->post(route('pos.service.store'), svcPayload([
            'walkin_name' => 'زائر الناسخ',
            'walkin_phone' => '0500000001',
            'walkin_tax_number' => '310123456700003',
        ]));

        $this->assertDatabaseHas('customers', [
            'phone' => '0500000001',
            'tax_number' => '310123456700003',
        ]);
    });

    it('fills the tax number of a matched walk-in customer only when empty', function () {
        $existing = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'phone' => '0500000001',
            'tax_number' => null,
        ]);

        $this->post(route('pos.service.store'), svcPayload([
            'walkin_name' => 'زائر',
            'walkin_phone' => '0500000001',
            'walkin_tax_number' => '310123456700003',
        ]));

        expect($existing->refresh()->tax_number)->toBe('310123456700003');

        // A later invoice with a different tax number must not overwrite it.
        $this->post(route('pos.service.store'), svcPayload([
            'walkin_name' => 'زائر',
            'walkin_phone' => '0500000001',
            'walkin_tax_number' => '310999999900003',
        ]));

        expect($existing->refresh()->tax_number)->toBe('310123456700003');
    });

    it('rejects a walk-in tax number that is not 15 digits', function () {
        $this->post(route('pos.service.store'), svcPayload([
            'walkin_name' => 'زائر',
            'walkin_tax_number' => '12345',
        ]))->assertSessionHasErrors('walkin_tax_number');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('applies a percentage coupon to the taxable base', function () {
        $coupon = Coupon::factory()->create([
            'branch_id' => $this->branch->id,
            'code' => 'save10',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'is_active' => true,
            'used_count' => 0,
        ]);

        $this->post(route('pos.service.store'), svcPayload(['coupon_code' => 'SAVE10']));

        $invoice = ServiceInvoice::firstOrFail();
        // subtotal 30، كوبون 3، فالإجمالي 27 شامل الضريبة
        expect((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->coupon_discount)->toBe(3.00)
            ->and((float) $invoice->total_amount)->toBe(27.00)
            ->and($invoice->coupon_id)->toBe($coupon->id);

        expect($coupon->refresh()->used_count)->toBe(1);
    });

    it('rejects an invalid coupon code', function () {
        $this->post(route('pos.service.store'), svcPayload(['coupon_code' => 'NOPE']))
            ->assertSessionHasErrors('coupon_code');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('applies an agent discount to the taxable base', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'discount', 'rate' => 10]);

        $this->post(route('pos.service.store'), svcPayload(['agent_ids' => [$agent->id]]));

        $invoice = ServiceInvoice::firstOrFail();
        $pivot = $invoice->invoiceAgents()->firstOrFail();
        // subtotal 30، خصم المندوب 10% = 3، فالإجمالي 27 شامل الضريبة، بلا ريبيت
        expect((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->agent_discount)->toBe(3.00)
            ->and((float) $pivot->rebate_amount)->toBe(0.00)
            ->and((float) $pivot->discount_amount)->toBe(3.00)
            ->and((float) $invoice->total_amount)->toBe(27.00)
            ->and($pivot->agent_id)->toBe($agent->id);
    });

    it('reduces employee commission and its ledger row by an agent discount', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'discount', 'rate' => 10]);

        $this->post(route('pos.service.store'), svcPayload(['agent_ids' => [$agent->id]]));

        $invoice = ServiceInvoice::firstOrFail();
        approveInvoice($invoice);

        // subtotal 30, agent discount 10% = 3, taxable base 27, net of VAT
        // 27 / 1.15 = 23.48. Commission is scaled by 23.48/30 = 0.7827: raw 3.00
        // -> 2.35, in the invoice, the line and the immutable ledger row alike.
        expect((float) $invoice->employee_commission)->toBe(2.35)
            ->and((float) $invoice->lines->first()->commission_amount)->toBe(2.35)
            ->and((float) CommissionLedger::firstOrFail()->amount)->toBe(2.35);
    });

    it('does not reduce employee commission by an agent rebate', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'rebate', 'rate' => 10]);

        $this->post(route('pos.service.store'), svcPayload(['agent_ids' => [$agent->id]]));

        $invoice = ServiceInvoice::firstOrFail();
        approveInvoice($invoice);

        // Rebate sits on top of the total and never touches the taxable base, so
        // commission stays at the full net-of-VAT figure (30 / 1.15 * 10%).
        expect((float) $invoice->employee_commission)->toBe(2.61)
            ->and((float) CommissionLedger::firstOrFail()->amount)->toBe(2.61);
    });

    it('records an agent rebate without deducting it from the total', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'rebate', 'rate' => 10]);

        $this->post(route('pos.service.store'), svcPayload(['agent_ids' => [$agent->id]]));

        $invoice = ServiceInvoice::firstOrFail();
        $pivot = $invoice->invoiceAgents()->firstOrFail();
        // subtotal 30 شاملة الضريبة وبلا خصم، فالإجمالي 30.
        // The rebate is earned net of VAT: 30 / 1.15 = 26.09, at 10% = 2.61.
        expect((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->agent_discount)->toBe(0.00)
            ->and((float) $invoice->total_amount)->toBe(30.00)
            ->and((float) $pivot->rebate_amount)->toBe(2.61)
            ->and($pivot->agent_id)->toBe($agent->id);
    });

    it('applies a fixed agent discount to the taxable base', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'discount', 'discount_type' => 'fixed', 'rate' => 5]);

        $this->post(route('pos.service.store'), svcPayload(['agent_ids' => [$agent->id]]));

        $invoice = ServiceInvoice::firstOrFail();
        $pivot = $invoice->invoiceAgents()->firstOrFail();
        // subtotal 30، خصم ثابت 5، فالإجمالي 25 شامل الضريبة، بلا ريبيت
        expect((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->agent_discount)->toBe(5.00)
            ->and((float) $pivot->rebate_amount)->toBe(0.00)
            ->and((float) $invoice->total_amount)->toBe(25.00)
            ->and($pivot->agent_id)->toBe($agent->id);
    });

    it('records a fixed agent rebate without deducting it from the total', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'rebate', 'discount_type' => 'fixed', 'rate' => 8]);

        $this->post(route('pos.service.store'), svcPayload(['agent_ids' => [$agent->id]]));

        $invoice = ServiceInvoice::firstOrFail();
        $pivot = $invoice->invoiceAgents()->firstOrFail();
        // subtotal 30 شاملة الضريبة وبلا خصم، فالإجمالي 30، والريبيت الثابت 8
        expect((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->agent_discount)->toBe(0.00)
            ->and((float) $invoice->total_amount)->toBe(30.00)
            ->and((float) $pivot->rebate_amount)->toBe(8.00)
            ->and($pivot->agent_id)->toBe($agent->id);
    });

    it('caps a fixed agent discount at the invoice base', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        // A fixed discount larger than the base must not push the total negative.
        setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'discount', 'discount_type' => 'fixed', 'rate' => 500]);

        $this->post(route('pos.service.store'), svcPayload(['agent_ids' => [$agent->id]]));

        $invoice = ServiceInvoice::firstOrFail();
        // subtotal 30, discount capped at 30, taxable 0, total 0
        expect((float) $invoice->agent_discount)->toBe(30.00)
            ->and((float) $invoice->total_amount)->toBe(0.00);
    });

    it('records several agents sharing the rebate on one invoice', function () {
        $a = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($a, $this->branch->id, ['discount_mode' => 'rebate', 'rate' => 10]);
        $b = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($b, $this->branch->id, ['discount_mode' => 'rebate', 'rate' => 5]);

        $this->post(route('pos.service.store'), svcPayload(['agent_ids' => [$a->id, $b->id]]));

        $invoice = ServiceInvoice::firstOrFail();
        // subtotal 30 شاملة الضريبة وبلا خصم، فالإجمالي 30. Rebates are earned
        // on the net-of-VAT 26.09: 10% = 2.61 and 5% = 1.3045→1.30
        expect((float) $invoice->total_amount)->toBe(30.00)
            ->and((float) $invoice->agent_discount)->toBe(0.00)
            ->and($invoice->invoiceAgents()->count())->toBe(2)
            ->and((float) $invoice->invoiceAgents()->where('agent_id', $a->id)->value('rebate_amount'))->toBe(2.61)
            ->and((float) $invoice->invoiceAgents()->where('agent_id', $b->id)->value('rebate_amount'))->toBe(1.30);
    });

    it('sums the discounts of several discount-mode agents', function () {
        $a = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($a, $this->branch->id, ['discount_mode' => 'discount', 'rate' => 10]);
        $b = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($b, $this->branch->id, ['discount_mode' => 'discount', 'rate' => 5]);

        $this->post(route('pos.service.store'), svcPayload(['agent_ids' => [$a->id, $b->id]]));

        $invoice = ServiceInvoice::firstOrFail();
        // subtotal 30، خصومات 10% + 5% = 4.50، فالإجمالي 25.50 شامل الضريبة
        expect((float) $invoice->agent_discount)->toBe(4.50)
            ->and((float) $invoice->total_amount)->toBe(25.50)
            ->and($invoice->invoiceAgents()->count())->toBe(2);
    });

    it('rejects an agent from another branch', function () {
        $otherBranch = Branch::factory()->create();
        $agent = Agent::factory()->create(['branch_id' => $otherBranch->id]);

        $this->post(route('pos.service.store'), svcPayload(['agent_ids' => [$agent->id]]))
            ->assertSessionHasErrors('agent_ids');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('redirects to the print page when print is requested', function () {
        $this->post(route('pos.service.store'), svcPayload(['print' => true]));

        $invoice = ServiceInvoice::firstOrFail();

        $this->get(route('pos.service.print', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('pos/service/print'));
    });

    it('prints a due invoice as a quotation without the branch tax number (تاسك 13)', function () {
        $this->post(route('pos.service.store'), svcPayload());

        $invoice = ServiceInvoice::firstOrFail();

        $this->get(route('pos.service.print', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('pos/service/print')
                ->where('invoice.status', 'due')
                ->where('branch.taxNumber', null));
    });

    it('prints an approved invoice as a tax invoice with the branch tax number (تاسك 13)', function () {
        $this->post(route('pos.service.store'), svcPayload());

        $invoice = ServiceInvoice::firstOrFail();
        approveInvoice($invoice);

        $this->get(route('pos.service.print', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('pos/service/print')
                ->where('invoice.status', 'paid')
                ->where('branch.taxNumber', $this->branch->tax_number));
    });
});
