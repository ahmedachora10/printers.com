<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ما يملكه المحاسب على فاتورة الموظف المعلّقة: إضافة بيانات العميل واختيار طريقة
 * الدفع، لا غير. الخدمات والكميات والأسعار وتكلفة الخامات تبقى لصاحب الفاتورة
 * ولمدير الفرع — فالمحاسب هو من يعتمد العمولة، فلا يُترك له تحريك أساسها.
 */
describe('Accountant scope on an employee invoice', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->cash = PaymentMethod::factory()->create(['name' => 'نقد', 'branch_id' => null]);

        $template = ServiceTemplate::factory()->create();
        BranchService::create([
            'branch_id' => $this->branch->id,
            'service_template_id' => $template->id,
            'base_commission_pct' => 10,
            'max_discount_pct' => 20,
            'is_tahazir' => false,
            'is_active' => true,
        ]);
        $this->service = BranchService::where('branch_id', $this->branch->id)
            ->where('service_template_id', $template->id)
            ->firstOrFail();

        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 10,
        ]);

        $this->invoice = scopeDueInvoice();
        $this->showUrl = route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]);
    });

    // ── ما لا يملكه: الخدمات والأسعار ──────────────────────────────

    it('hides the edit control from him and shows the two he does own', function () {
        $this->actingAs($this->accountant)
            ->get($this->showUrl)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.canEdit', false)
                ->where('invoice.canEditCustomer', true)
                ->where('invoice.canEditPaymentMethod', true));
    });

    it('keeps the full edit for the branch admin', function () {
        $this->actingAs($this->branchAdmin)
            ->get($this->showUrl)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoice.canEdit', true));
    });

    it('drops the edit button from his review queue row', function () {
        $this->actingAs($this->accountant)
            ->get(route('invoices.service.review'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoices.0.canEdit', false));

        $this->actingAs($this->branchAdmin)
            ->get(route('invoices.service.review'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoices.0.canEdit', true));
    });

    it('drops it from his invoice-list row too', function () {
        $this->actingAs($this->accountant)
            ->get(route('invoices.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('items.data.0.canEdit', false)
                // بيانات العميل تبقى له في الصف كما كانت.
                ->where('items.data.0.canEditCustomer', true));
    });

    it('refuses him the edit screen and any rewrite of the lines', function () {
        $this->actingAs($this->accountant)->get(route('pos.service.edit', $this->invoice))->assertForbidden();

        $this->actingAs($this->accountant)->put(route('pos.service.update', $this->invoice), [
            'payment_method_id' => $this->cash->id,
            'lines' => [['branch_service_id' => $this->service->id, 'qty' => 5, 'unit_price' => 400]],
        ])->assertForbidden();

        expect((float) $this->invoice->refresh()->total_amount)->toBe(100.00)
            ->and($this->invoice->lines()->first()->qty)->toBe(1);
    });

    // ── ما يملكه: العميل وطريقة الدفع ──────────────────────────────

    it('registers the customer of a walk-in invoice from the invoice screen', function () {
        $this->actingAs($this->accountant)
            ->from($this->showUrl)
            ->patch(route('invoices.service.update-customer', $this->invoice), [
                'full_name' => 'عبدالله المطيري',
                'phone' => '0551234567',
                'tax_number' => '',
            ])
            // يعود إلى الفاتورة التي جاء منها، لا إلى طابور المراجعة.
            ->assertRedirect($this->showUrl);

        $customer = Customer::where('phone', '0551234567')->firstOrFail();

        expect($this->invoice->refresh()->customer_id)->toBe($customer->id)
            ->and($customer->full_name)->toBe('عبدالله المطيري');
    });

    it('names the payment method from the invoice screen and stays there', function () {
        $this->actingAs($this->accountant)
            ->from($this->showUrl)
            ->patch(route('invoices.service.update-payment-method', $this->invoice), [
                'payment_method_id' => $this->cash->id,
            ])->assertRedirect($this->showUrl);

        expect($this->invoice->refresh()->payment_method_id)->toBe($this->cash->id);
    });

    it('still holds him to a method his branch can see', function () {
        $foreign = PaymentMethod::factory()->create([
            'name' => 'طريقة فرع آخر',
            'branch_id' => Branch::factory()->create()->id,
        ]);

        $this->actingAs($this->accountant)
            ->from($this->showUrl)
            ->patch(route('invoices.service.update-payment-method', $this->invoice), [
                'payment_method_id' => $foreign->id,
            ])->assertSessionHasErrors('payment_method_id');

        expect($this->invoice->refresh()->payment_method_id)->toBeNull();
    });
});

/** فاتورة خدمة معلّقة بلا عميل ولا طريقة دفع — كما يرفعها الموظف اليوم. */
function scopeDueInvoice(): ServiceInvoice
{
    test()->actingAs(test()->employee)
        ->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [
                ['branch_service_id' => test()->service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
            ],
        ])->assertRedirect();

    return ServiceInvoice::latest('id')->firstOrFail();
}
