<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * طريقة الدفع إلزامية على من يُغلق الفاتورة بنفسه — المحاسب ومدير الفرع — في
 * الإنشاء والتعديل معاً، واختيارية على الموظف: فاتورته تُحفظ معلّقة والمحاسب هو
 * من يحدّد كيف حُصِّلت عند الاعتماد. والاعتماد نفسه لا يمرّ بلا طريقة، فلا تُغلق
 * فاتورةٌ مدفوعةٌ بلا واحدة. وأياً كان الدور، لا تُقبل إلا طريقةٌ يراها الفرع.
 */
describe('Payment method: optional for the employee, required of whoever settles', function () {
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

        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => ProductCategory::factory()->create()->id,
            'unit_id' => ProductUnit::factory()->create()->id,
            'selling_price' => 10.00,
            'cost_price' => 6.00,
            'current_stock' => 0,
        ]);

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

        // فاتورة خدمة معلّقة كما يرسلها الموظف — طريقة الدفع تُمرَّر أو تُترك.
        $this->serviceLines = [
            ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
        ];
    });

    // ── فاتورة المنتجات: المحاسب، فالقاعدة لم تتغيّر ────────────────

    it('refuses a product invoice with no payment method', function () {
        $this->actingAs($this->accountant)
            ->post(route('pos.product.store'), [
                'status' => 'paid',
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])->assertSessionHasErrors('payment_method_id');

        $this->assertDatabaseCount('product_invoices', 0);
    });

    it('rejects a payment method that belongs to another branch', function () {
        $foreign = PaymentMethod::factory()->create([
            'name' => 'طريقة فرع آخر',
            'branch_id' => Branch::factory()->create()->id,
        ]);

        $this->actingAs($this->accountant)
            ->post(route('pos.product.store'), [
                'status' => 'paid',
                'payment_method_id' => $foreign->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])->assertSessionHasErrors('payment_method_id');

        $this->assertDatabaseCount('product_invoices', 0);
    });

    // ── إنشاء فاتورة خدمة ──────────────────────────────────────────

    it('lets an employee raise a due invoice with no payment method', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'lines' => $this->serviceLines,
            ])->assertRedirect(route('pos.service.create'));

        $invoice = ServiceInvoice::firstOrFail();

        expect($invoice->payment_method_id)->toBeNull()
            ->and($invoice->status)->toBe(InvoiceStatusEnum::DUE);
    });

    it('still binds the employee to a method his own branch can see', function () {
        // الإعفاء من الإلزام لا يعني قبول أي معرِّف: طريقة فرعٍ آخر مرفوضة عليه
        // كما ترفض على المحاسب.
        $foreign = PaymentMethod::factory()->create([
            'name' => 'طريقة فرع آخر',
            'branch_id' => Branch::factory()->create()->id,
        ]);

        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'payment_method_id' => $foreign->id,
                'lines' => $this->serviceLines,
            ])->assertSessionHasErrors('payment_method_id');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('still demands one from a branch admin raising a due invoice', function () {
        // مدير الفرع يستطيع إغلاق الفاتورة بنفسه، فلا يُعفى ولو حفظها معلّقة.
        $this->actingAs($this->branchAdmin)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'lines' => $this->serviceLines,
            ])->assertSessionHasErrors('payment_method_id');

        expect(ServiceInvoice::count())->toBe(0);
    });

    // ── تعديل فاتورة خدمة معلّقة ───────────────────────────────────

    it('lets the owning employee re-edit his invoice without one', function () {
        $invoice = employeeDueInvoice();

        $this->actingAs($this->employee)
            ->put(route('pos.service.update', $invoice), [
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 2, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])->assertRedirect();

        expect($invoice->refresh()->payment_method_id)->toBeNull()
            ->and($invoice->lines()->first()->qty)->toBe(2);
    });

    it('refuses a reviewer edit that leaves the method unset', function () {
        // القاعدة تتبع من يعدّل الآن: مدير الفرع يراجع تمهيداً للاعتماد، فيُلزم بها
        // ولو كانت الفاتورة قد حُفظت بلا طريقة. (المحاسب لا يفتح هذه الشاشة أصلاً —
        // AccountantInvoiceScopeTest.)
        $invoice = employeeDueInvoice();

        $this->actingAs($this->branchAdmin)
            ->put(route('pos.service.update', $invoice), ['lines' => $this->serviceLines])
            ->assertSessionHasErrors('payment_method_id');

        $this->actingAs($this->branchAdmin)
            ->put(route('pos.service.update', $invoice), [
                'payment_method_id' => $this->cash->id,
                'lines' => $this->serviceLines,
            ])->assertRedirect();

        expect($invoice->refresh()->payment_method_id)->toBe($this->cash->id);
    });

    it('still accepts a method that was disabled after the invoice was raised', function () {
        $card = PaymentMethod::factory()->create(['name' => 'بطاقة', 'branch_id' => null]);
        $invoice = employeeDueInvoice(['payment_method_id' => $card->id]);

        $card->update(['is_active' => false]);

        // التعديل لا يتعثّر بطريقةٍ عُطّلت بعد إصدار الفاتورة — تبقى مقبولةً لها وحدها.
        $this->actingAs($this->branchAdmin)
            ->put(route('pos.service.update', $invoice), [
                'payment_method_id' => $card->id,
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 2, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])->assertRedirect();

        expect($invoice->refresh()->payment_method_id)->toBe($card->id)
            ->and($invoice->lines()->first()->qty)->toBe(2);
    });

    // ── الشبكة الأمانية: الاعتماد ──────────────────────────────────

    it('holds the accountant at approval until he names the method', function () {
        // هذا ما يجعل إعفاء الموظف آمناً: الفاتورة لا تُغلق مدفوعةً بلا طريقة.
        $invoice = employeeDueInvoice();

        $this->actingAs($this->accountant)
            ->patch(route('invoices.service.pay', $invoice))
            ->assertSessionHasErrors('payment_method_id');

        expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::DUE);

        $this->actingAs($this->accountant)
            ->patch(route('invoices.service.update-payment-method', $invoice), [
                'payment_method_id' => $this->cash->id,
            ])->assertRedirect();

        $this->actingAs($this->accountant)
            ->patch(route('invoices.service.pay', $invoice))
            ->assertRedirect();

        expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::PAID)
            ->and($invoice->payment_method_id)->toBe($this->cash->id);
    });
});

/** فاتورة خدمة معلّقة ينشئها الموظف من نقطة البيع — بلا طريقة دفع ما لم تُمرَّر. */
function employeeDueInvoice(array $overrides = []): ServiceInvoice
{
    test()->actingAs(test()->employee)
        ->post(route('pos.service.store'), array_merge([
            'status' => 'due',
            'lines' => test()->serviceLines,
        ], $overrides))->assertRedirect();

    return ServiceInvoice::latest('id')->firstOrFail();
}
