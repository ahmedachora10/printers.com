<?php

use App\Enums\AgentDiscountModeEnum;
use App\Enums\CustomerTierEnum;
use App\Enums\CustomerTypeEnum;
use App\Enums\LoyaltyTransactionTypeEnum;
use App\Enums\Roles;
use App\Models\AgentPayment;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceAgent;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeEditService(): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'is_tahazir' => false,
        'is_active' => true,
    ]);

    return BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

/**
 * Create a DUE invoice owned by the acting employee through the real POS flow.
 */
function makeOwnedDueInvoice(array $lineOverrides = []): ServiceInvoice
{
    $line = array_merge(
        ['branch_service_id' => test()->service->id, 'qty' => 3, 'unit_price' => 10, 'discount_pct' => 0],
        $lineOverrides,
    );

    test()->post(route('pos.service.store'), [
        'status' => 'due',
        'payment_method_id' => paymentMethodId(),
        'lines' => [$line],
    ]);

    return ServiceInvoice::latest('id')->firstOrFail();
}

describe('Service invoice edit/return', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->service = makeEditService();
        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 10,
        ]);

        $this->actingAs($this->employee);
    });

    // ---- Edit -------------------------------------------------------------

    it('lets the owner employee open the edit screen for a due invoice', function () {
        $invoice = makeOwnedDueInvoice();

        $this->get(route('pos.service.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos/service/index')
                ->where('invoice.id', $invoice->id)
                ->where('invoice.invoiceNumber', $invoice->invoice_number)
                ->has('invoice.lines', 1));
    });

    it('updates a due invoice in place, keeping its number and recomputing commission', function () {
        $invoice = makeOwnedDueInvoice(); // subtotal 30, commission 3
        $number = $invoice->invoice_number;

        $this->put(route('pos.service.update', $invoice), [
            'payment_method_id' => paymentMethodId(),
            'lines' => [
                ['branch_service_id' => $this->service->id, 'qty' => 5, 'unit_price' => 10, 'discount_pct' => 0],
            ],
        ])->assertRedirect(route('invoices.show', ['type' => 'service', 'id' => $invoice->id]))
            ->assertSessionHas('success');

        $invoice->refresh();

        expect($invoice->invoice_number)->toBe($number)
            ->and($invoice->status->value)->toBe('due')
            ->and((float) $invoice->subtotal)->toBe(50.00)
            // Net of VAT 50 / 1.15 = 43.48, at 10% = 4.35.
            ->and((float) $invoice->employee_commission)->toBe(4.35)
            // السعر شامل الضريبة، فالإجمالي هو المجموع الفرعي نفسه.
            ->and((float) $invoice->total_amount)->toBe(50.00)
            ->and($invoice->lines()->count())->toBe(1);

        // A due invoice carries no ledger yet; approving it writes the recomputed
        // commission (4.35, not the original 2.61) to the immutable ledger.
        expect(CommissionLedger::count())->toBe(0);

        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));

        expect((float) CommissionLedger::where('user_id', $this->employee->id)->sum('amount'))->toBe(4.35);
    });

    it('forbids editing a paid invoice', function () {
        $invoice = makeOwnedDueInvoice();
        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));
        $this->actingAs($this->employee);

        $this->get(route('pos.service.edit', $invoice))->assertForbidden();
        $this->put(route('pos.service.update', $invoice), [
            'payment_method_id' => paymentMethodId(),
            'lines' => [['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 10]],
        ])->assertForbidden();
    });

    it('forbids an employee from editing another employee\'s invoice', function () {
        $invoice = makeOwnedDueInvoice();

        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($other)->get(route('pos.service.edit', $invoice))->assertForbidden();
    });

    // ---- تاسك 70: المراجع يصحّح قبل الاعتماد -----------------------------

    it('lets a branch admin edit a due invoice raised by an employee in their branch', function () {
        // الخدمة تحمل خامات بـ 5 للوحدة — وهذا بالضبط ما لا يملك الموظف تغييره.
        $this->service->update(['has_materials' => true, 'materials_cost' => 5]);

        $invoice = makeOwnedDueInvoice(); // 3 × 10 = 30 شاملة الضريبة ← صافٍ 26.09، خامات 15

        expect((float) $invoice->lines()->firstOrFail()->commission_amount)->toEqual(1.11);

        $this->actingAs($this->branchAdmin)
            ->put(route('pos.service.update', $invoice), [
                'payment_method_id' => paymentMethodId(),
                'lines' => [[
                    'branch_service_id' => $this->service->id,
                    'qty' => 3,
                    'unit_price' => 10,
                    'discount_pct' => 0,
                    'has_materials' => true,
                    'materials_cost' => 2,
                ]],
            ])->assertRedirect(route('invoices.show', ['type' => 'service', 'id' => $invoice->id]));

        $invoice->refresh();
        $line = $invoice->lines()->firstOrFail();

        // الخامات نزلت إلى 6، فقاعدة العمولة 20.09 وعند 10% = 2.01 — والفاتورة
        // تبقى للموظف ومعلّقة: التعديل ليس اعتماداً.
        expect((float) $line->materials_cost)->toEqual(2.00)
            ->and((float) $line->commission_amount)->toEqual(2.01)
            ->and((float) $invoice->employee_commission)->toEqual(2.01)
            ->and($invoice->user_id)->toBe($this->employee->id)
            ->and($invoice->status->value)->toBe('due');

        // وعند الاعتماد يُكتب الرقم المصحّح للموظف لا لمن عدّل.
        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice->refresh())));

        expect((float) CommissionLedger::where('user_id', $this->employee->id)->sum('amount'))->toEqual(2.01)
            ->and(CommissionLedger::where('user_id', $this->branchAdmin->id)->count())->toBe(0);
    });

    it('lets an accountant in the branch edit a due invoice', function () {
        $invoice = makeOwnedDueInvoice();

        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        $this->actingAs($accountant)
            ->get(route('pos.service.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos/service/index')
                ->where('invoice.id', $invoice->id)
                ->where('invoice.isOwn', false)
                ->where('invoice.employeeName', $this->employee->name));
    });

    it('seeds a reviewer edit screen with the invoice owner commission rate', function () {
        $invoice = makeOwnedDueInvoice();

        // مدير الفرع لا يملك صفّ user_services أصلاً؛ لو قُرئت النسبة به لظهرت
        // صفراً على الشاشة بينما يحسب الخادم 10% عند الحفظ.
        $this->actingAs($this->branchAdmin)
            ->get(route('pos.service.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('services.0.baseCommissionPct', 10)
                ->where('invoice.lines.0.baseCommissionPct', 10));
    });

    it('forbids a reviewer from another branch', function () {
        $invoice = makeOwnedDueInvoice();

        $otherBranch = Branch::factory()->create();
        $otherAdmin = User::factory()->create();
        $otherAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $otherBranch->update(['owner_id' => $otherAdmin->id]);

        $otherAccountant = User::factory()->create(['branch_id' => $otherBranch->id]);
        $otherAccountant->addRole(Roles::ACCOUNTANT->value);

        $this->actingAs($otherAdmin)->get(route('pos.service.edit', $invoice))->assertForbidden();
        $this->actingAs($otherAccountant)->get(route('pos.service.edit', $invoice))->assertForbidden();
    });

    it('forbids a reviewer from editing an approved invoice', function () {
        $invoice = makeOwnedDueInvoice();
        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));

        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        // الاعتماد يكتب commission_ledger غير القابل للنقض، فيُقفل التحرير على الجميع.
        $this->actingAs($this->branchAdmin)->get(route('pos.service.edit', $invoice))->assertForbidden();
        $this->actingAs($accountant)->get(route('pos.service.edit', $invoice))->assertForbidden();
        $this->actingAs($accountant)->put(route('pos.service.update', $invoice), [
            'payment_method_id' => paymentMethodId(),
            'lines' => [['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 10]],
        ])->assertForbidden();
    });

    it('still refuses the owning employee the materials cost on their own edit', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 5]);

        $invoice = makeOwnedDueInvoice();

        $this->put(route('pos.service.update', $invoice), [
            'payment_method_id' => paymentMethodId(),
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 3,
                'unit_price' => 10,
                'discount_pct' => 0,
                'has_materials' => false,
                'materials_cost' => 0,
            ]],
        ])->assertRedirect();

        // تاسك 54 لم ينكسر: الموظف يعدّل فاتورته، وتبقى خامات الخدمة كما هي.
        expect((float) $invoice->fresh()->lines()->firstOrFail()->materials_cost)->toEqual(5.00);
    });

    // ---- Return (استرجاع) ---------------------------------------------------

    it('returns a due invoice without deleting it and books no refund', function () {
        $invoice = makeOwnedDueInvoice();

        $this->post(route('pos.service.return', $invoice))
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHas('success');

        $invoice->refresh();

        // The row stays visible, only the status moves. A due invoice never
        // collected money (nor earned commission), so no refund is booked.
        expect($invoice->trashed())->toBeFalse()
            ->and($invoice->status->value)->toBe('returned')
            ->and(Refund::where('invoice_id', $invoice->id)->count())->toBe(0)
            ->and((float) CommissionLedger::where('user_id', $this->employee->id)->sum('amount'))->toBe(0.00);
    });

    it('books a refund for the full total and reverses commission when returning a paid invoice', function () {
        $invoice = makeOwnedDueInvoice(); // total 30.00 شامل الضريبة، commission 2.61
        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));

        expect((float) CommissionLedger::where('user_id', $this->employee->id)->sum('amount'))->toBe(2.61);

        $this->actingAs($this->employee)
            ->post(route('pos.service.return', $invoice), ['reason' => 'العميل ألغى الطلب'])
            ->assertRedirect(route('invoices.index'));

        $invoice->refresh();
        $refund = Refund::where('invoice_id', $invoice->id)->where('invoice_type', $invoice->getMorphClass())->sole();

        expect($invoice->trashed())->toBeFalse()
            ->and($invoice->status->value)->toBe('returned')
            ->and((float) $refund->amount)->toBe(30.00)
            ->and($refund->reason)->toBe('العميل ألغى الطلب')
            ->and($refund->user_id)->toBe($this->employee->id)
            // The reversal is a new negative row — the ledger is never mutated.
            ->and(CommissionLedger::where('user_id', $this->employee->id)->count())->toBe(2)
            ->and((float) CommissionLedger::where('user_id', $this->employee->id)->sum('amount'))->toBe(0.00);
    });

    it('refunds only the remainder when the invoice was already partially refunded', function () {
        $invoice = makeOwnedDueInvoice(); // total 30.00 شامل الضريبة، commission 2.61
        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));

        $this->actingAs($this->branchAdmin)->post(route('refunds.store'), [
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'amount' => 10,
            'reason' => 'مرتجع جزئي',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->employee)->post(route('pos.service.return', $invoice))
            ->assertRedirect(route('invoices.index'));

        $invoice->refresh();

        expect($invoice->status->value)->toBe('returned')
            ->and(Refund::where('invoice_id', $invoice->id)->count())->toBe(2)
            ->and((float) Refund::where('invoice_id', $invoice->id)->sum('amount'))->toBe(30.00)
            // ما عُكس مع المرتجع الجزئي + ما عُكس مع الاسترجاع = 2.61 كاملة.
            ->and(round((float) CommissionLedger::where('user_id', $this->employee->id)->sum('amount'), 2) + 0)->toBe(0.00);
    });

    it('refuses to return an invoice that is already returned', function () {
        $invoice = makeOwnedDueInvoice();

        $this->post(route('pos.service.return', $invoice))->assertRedirect(route('invoices.index'));

        // The policy denies a second return outright — no duplicate refund, and
        // the accruals are never unwound twice.
        $this->post(route('pos.service.return', $invoice))->assertForbidden();

        expect(Refund::where('invoice_id', $invoice->id)->count())->toBe(0)
            ->and($invoice->refresh()->status->value)->toBe('returned');
    });

    it('claws back earned points when returning a paid invoice', function () {
        LoyaltyConfig::query()->create(['branch_id' => $this->branch->id, 'earning_rate' => 1, 'is_active' => true]);

        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_type' => CustomerTypeEnum::Individual,
            'points_balance' => 0,
            'cumulative_spend' => 0,
        ]);

        $invoice = makeOwnedDueInvoice(); // total 30.00 شامل الضريبة
        $invoice->update(['customer_id' => $customer->id]);

        // Approve → النقاط على الصافي من الضريبة: floor(30 ÷ 1.15) = 26.
        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));
        expect($customer->refresh()->points_balance)->toBe(26);

        $this->actingAs($this->employee)->post(route('pos.service.return', $invoice))
            ->assertRedirect(route('invoices.index'));

        $customer->refresh();
        expect($invoice->refresh()->status->value)->toBe('returned')
            ->and($customer->points_balance)->toBe(0)
            ->and((float) $customer->cumulative_spend)->toBe(0.00)
            ->and((float) CommissionLedger::where('user_id', $this->employee->id)->sum('amount'))->toBe(0.00)
            ->and(LoyaltyTransaction::where('customer_id', $customer->id)
                ->where('type', LoyaltyTransactionTypeEnum::ManualAdjust)
                ->where('points', -26)
                ->exists())->toBeTrue();
    });

    // الإنفاق يُضاف ويُخصم بالمقياس نفسه (الإجمالي شامل الضريبة)، والفئة تتبعه
    // هبوطاً — فكان الإرجاع يخصم 15% زيادة ويترك الفئة معلّقة فوق إنفاقٍ لم يعد
    // يبلغها.
    it('drops the tier when returning the invoice takes the spend below its threshold', function () {
        LoyaltyConfig::query()->create([
            'branch_id' => $this->branch->id,
            'earning_rate' => 1,
            'is_active' => true,
            'bronze_threshold' => 20,
            'silver_threshold' => 2000,
            'gold_threshold' => 5000,
        ]);

        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_type' => CustomerTypeEnum::Individual,
            'points_balance' => 0,
            'cumulative_spend' => 0,
            'tier' => CustomerTierEnum::None,
        ]);

        $invoice = makeOwnedDueInvoice(); // total 30.00 شامل الضريبة
        $invoice->update(['customer_id' => $customer->id]);

        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));

        // 30.00 يبلغ حدّ البرونزي (20) فيترقّى.
        expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Bronze)
            ->and((float) $customer->cumulative_spend)->toBe(30.00);

        $this->actingAs($this->employee)->post(route('pos.service.return', $invoice));

        // والإرجاع يعيد الإنفاق إلى الصفر فتسقط الفئة معه.
        expect($customer->refresh()->tier)->toBe(CustomerTierEnum::None)
            ->and((float) $customer->cumulative_spend)->toBe(0.00);
    });

    it('blocks returning an invoice already rolled into an agent payment', function () {
        $invoice = makeOwnedDueInvoice();
        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));

        $agent = User::factory()->create(['branch_id' => $this->branch->id]);
        $agent->addRole(Roles::AGENT->value);

        $payment = AgentPayment::create([
            'agent_id' => $agent->id,
            'branch_id' => $this->branch->id,
            'period_start' => now()->subMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'total_invoices' => 1,
            'total_rebate' => 5,
            'paid_by' => $this->branchAdmin->id,
            'paid_at' => now(),
        ]);

        ServiceInvoiceAgent::create([
            'service_invoice_id' => $invoice->id,
            'agent_id' => $agent->id,
            'discount_mode' => AgentDiscountModeEnum::Rebate,
            'rate' => 5,
            'rebate_amount' => 5,
            'discount_amount' => 0,
            'agent_payment_id' => $payment->id,
        ]);

        $this->actingAs($this->employee)->post(route('pos.service.return', $invoice))
            ->assertSessionHasErrors('invoice');

        expect($invoice->refresh()->status->value)->toBe('paid')
            ->and(Refund::where('invoice_id', $invoice->id)->count())->toBe(0);
    });

    it('forbids an accountant from returning an employee invoice', function () {
        $invoice = makeOwnedDueInvoice();

        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        $this->actingAs($accountant)->post(route('pos.service.return', $invoice))->assertForbidden();

        expect($invoice->refresh()->status->value)->toBe('due');
    });

    it('forbids returning another employee\'s invoice', function () {
        $invoice = makeOwnedDueInvoice();

        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($other)->post(route('pos.service.return', $invoice))->assertForbidden();
    });

    // ---- Customer details from the POS edit screen --------------------------

    it('lets the owner employee correct the customer name, phone and tax number of a due invoice', function () {
        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'full_name' => 'اسم قديم',
            'phone' => '0500000001',
            'tax_number' => null,
        ]);
        $invoice = makeOwnedDueInvoice();
        $invoice->update(['customer_id' => $customer->id]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => 'اسم جديد',
            'phone' => '0500000002',
            'tax_number' => '310000000000003',
        ])->assertRedirect()->assertSessionHas('success');

        expect($customer->refresh())
            ->full_name->toBe('اسم جديد')
            ->phone->toBe('0500000002')
            ->tax_number->toBe('310000000000003');
    });

    it('surfaces the customer tax number on the edit screen', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'tax_number' => '310000000000003']);
        $invoice = makeOwnedDueInvoice();
        $invoice->update(['customer_id' => $customer->id]);

        $this->get(route('pos.service.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoice.customer.taxNumber', '310000000000003'));
    });

    it('rejects a tax number that is not exactly 15 digits', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'tax_number' => null]);
        $invoice = makeOwnedDueInvoice();
        $invoice->update(['customer_id' => $customer->id]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => $customer->full_name,
            'phone' => $customer->phone,
            'tax_number' => '3100000',
        ])->assertSessionHasErrors('tax_number');

        expect($customer->refresh()->tax_number)->toBeNull();
    });

    it('rejects a phone already taken by another customer in the branch', function () {
        $other = Customer::factory()->create(['branch_id' => $this->branch->id, 'phone' => '0500000009']);
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'phone' => '0500000001']);
        $invoice = makeOwnedDueInvoice();
        $invoice->update(['customer_id' => $customer->id]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => $customer->full_name,
            'phone' => $other->phone,
            'tax_number' => '',
        ])->assertSessionHasErrors(['phone' => 'رقم الجوال مستخدم لعميل آخر.']);

        expect($customer->refresh()->phone)->toBe('0500000001');
    });

    it('registers and links a customer when the due invoice has none', function () {
        $invoice = makeOwnedDueInvoice();

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => 'عميل عابر',
            'phone' => '0500000007',
            'tax_number' => '',
        ])->assertRedirect()->assertSessionHas('success');

        $customer = Customer::where('phone', '0500000007')->firstOrFail();
        expect($customer->branch_id)->toBe($this->branch->id)
            ->and($invoice->refresh()->customer_id)->toBe($customer->id);
    });

    it('refuses to rewrite a corporate customer from the employee POS screen', function () {
        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_type' => CustomerTypeEnum::Corporate,
            'company_name' => 'شركة الاختبار',
            'full_name' => 'اسم قديم',
        ]);
        $invoice = makeOwnedDueInvoice();
        $invoice->update(['customer_id' => $customer->id]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => 'اسم جديد',
            'phone' => $customer->phone,
            'tax_number' => '',
        ])->assertSessionHasErrors('full_name');

        expect($customer->refresh()->full_name)->toBe('اسم قديم');
    });

    it('forbids editing the customer on another employee\'s invoice', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'tax_number' => null]);
        $invoice = makeOwnedDueInvoice();
        $invoice->update(['customer_id' => $customer->id]);

        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($other)
            ->patch(route('invoices.service.update-customer', $invoice), [
                'full_name' => 'اسم جديد',
                'phone' => $customer->phone,
                'tax_number' => '310000000000003',
            ])->assertForbidden();

        expect($customer->refresh()->tax_number)->toBeNull();
    });

    it('forbids the employee editing the customer once the invoice is paid', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'tax_number' => null]);
        $invoice = makeOwnedDueInvoice();
        $invoice->update(['customer_id' => $customer->id, 'status' => 'paid', 'paid_at' => now()]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => 'اسم جديد',
            'phone' => $customer->phone,
            'tax_number' => '310000000000003',
        ])->assertForbidden();
    });

    // ---- Line notes --------------------------------------------------------

    it('stores the free-text detail typed against a service line', function () {
        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 1,
                'unit_price' => 10,
                'discount_pct' => 0,
                'notes' => '  ورق مقوّى 300 جرام  ',
            ]],
        ])->assertRedirect();

        $line = ServiceInvoice::latest('id')->firstOrFail()->lines()->firstOrFail();

        expect($line->notes)->toBe('ورق مقوّى 300 جرام');
    });

    it('collapses a blank detail box to null', function () {
        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 1,
                'unit_price' => 10,
                'discount_pct' => 0,
                'notes' => '   ',
            ]],
        ])->assertRedirect();

        expect(ServiceInvoice::latest('id')->firstOrFail()->lines()->firstOrFail()->notes)->toBeNull();
    });

    it('rejects a detail longer than 500 characters', function () {
        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 1,
                'unit_price' => 10,
                'discount_pct' => 0,
                'notes' => str_repeat('ا', 501),
            ]],
        ])->assertSessionHasErrors('lines.0.notes');
    });

    it('keeps the line detail when the invoice is re-edited', function () {
        $invoice = makeOwnedDueInvoice();

        $this->put(route('pos.service.update', $invoice), [
            'payment_method_id' => paymentMethodId(),
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 2,
                'unit_price' => 10,
                'discount_pct' => 0,
                'notes' => 'تسليم الخميس',
            ]],
        ])->assertRedirect();

        expect($invoice->refresh()->lines()->firstOrFail()->notes)->toBe('تسليم الخميس');
    });

    it('surfaces the line detail on the edit screen', function () {
        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 1,
                'unit_price' => 10,
                'discount_pct' => 0,
                'notes' => 'تسليم الخميس',
            ]],
        ]);

        $invoice = ServiceInvoice::latest('id')->firstOrFail();

        $this->get(route('pos.service.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoice.lines.0.notes', 'تسليم الخميس'));
    });
});
