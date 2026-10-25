<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\DeliveryProvider;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * تاسك 111 — «تسليم مبلغ التوصيل»: مصروفٌ معتمد مربوطٌ بالطلب وسائقه. النقدي
 * يُطرح من نقد الدرج والتحويل لا يمسّه، وحالة الفاتورة لا تتغيّر.
 */
describe('Delivery settlement', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->driver = DeliveryProvider::factory()->create(['branch_id' => $this->branch->id, 'name' => 'أبو محمد']);
        $this->category = ExpenseCategory::factory()->create(['name' => 'توصيل']);

        // طلبٌ «مجاني» مسدَّد — تاسك 193: لا تسوية قبل سداد الفاتورة.
        $this->invoice = ServiceInvoice::create([
            'invoice_number' => 'SINV-018-00025',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'subtotal' => 100,
            'shipping_fee' => 0,
            'vat_pct' => 15,
            'vat_amount' => 13.04,
            'total_amount' => 100,
            'employee_commission' => 0,
            'status' => InvoiceStatusEnum::PAID,
            'paid_at' => now(),
        ]);
        $this->shipment = $this->invoice->shipments()->create(['provider_id' => $this->driver->id, 'fee' => 0]);

        // تاسك 187: التحويل من حساب الفرع البنكي.
        $this->bankAccount = BankAccount::create(['branch_id' => $this->branch->id, 'name' => 'الحساب الرئيسي']);
        $this->settle = fn (string $source, $amount = 25) => $this->actingAs($this->admin)
            ->post(route('shipping.deliveries.settle', $this->shipment), [
                'amount' => $amount,
                'paid_from' => $source,
                'bank_account_id' => $source === 'company_transfer' ? $this->bankAccount->id : null,
                'expense_category_id' => $this->category->id,
            ]);
    });

    it('records a cash settlement as an approved linked expense taken off the drawer cash', function () {
        PaymentMethod::factory()->cash()->create();

        ($this->settle)('cash_drawer')->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();
        expect($expense->invoices->pluck('id')->all())->toBe([$this->invoice->id])
            ->and($expense->service_invoice_shipment_id)->toBe($this->shipment->id)
            ->and($expense->delivery_provider_id)->toBe($this->driver->id)
            ->and((float) $expense->total)->toBe(25.0)
            ->and($expense->isApproved())->toBeTrue()
            ->and($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::PAID);

        $this->actingAs($this->admin)->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page->where('totals.cashExpenses', 25));

        $this->actingAs($this->admin)->get(route('shipping.deliveries'))
            ->assertInertia(fn ($page) => $page->where('deliveries.data.0.settlement.amount', 25));
    });

    it('keeps a transfer settlement out of the drawer cash', function () {
        ($this->settle)('company_transfer')->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page->where('totals.expenses', 25)->where('totals.cashExpenses', 0));
    });

    // تاسك 193 — الزرّ في الفاتورة وكشف التوصيل يمرّان بالحارس نفسه.
    it('refuses to settle before the invoice is fully paid', function () {
        $this->invoice->update(['status' => InvoiceStatusEnum::DUE, 'paid_at' => null]);

        ($this->settle)('cash_drawer')->assertSessionHasErrors(['amount' => 'لا يُسلَّم مبلغ التوصيل قبل سداد الفاتورة كاملاً']);
        expect(Expense::count())->toBe(0);
        $this->actingAs($this->admin)->get(route('shipping.deliveries'))
            ->assertInertia(fn ($page) => $page->where('deliveries.data.0.invoicePaid', false));

        $this->invoice->update(['status' => InvoiceStatusEnum::PAID, 'paid_at' => now()]);

        ($this->settle)('cash_drawer')->assertSessionHasNoErrors();
        expect(Expense::count())->toBe(1);
    });

    it('refuses a second settlement and a zero amount', function () {
        ($this->settle)('cash_drawer', 0)->assertSessionHasErrors('amount');
        ($this->settle)('cash_drawer')->assertSessionHasNoErrors();
        ($this->settle)('cash_drawer')->assertSessionHasErrors('amount');

        expect(Expense::count())->toBe(1);
    });

    // تاسك 170 — فاتورةٌ بسائقين: كلٌّ يُسوّى على حدة، وتسوية أحدهما لا تُقفل الآخر.
    it('settles each shipment of one invoice with its own driver', function () {
        $other = DeliveryProvider::factory()->create(['branch_id' => $this->branch->id, 'name' => 'شركة ثريا']);
        $second = $this->invoice->shipments()->create(['provider_id' => $other->id, 'fee' => 0]);

        ($this->settle)('cash_drawer')->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->post(route('shipping.deliveries.settle', $second), [
                'amount' => 40,
                'paid_from' => 'cash_drawer',
                'expense_category_id' => $this->category->id,
            ])
            ->assertSessionHasNoErrors();

        expect(Expense::orderBy('id')->get()->map(fn ($e) => [$e->service_invoice_shipment_id, $e->delivery_provider_id, (float) $e->total])->all())
            ->toBe([[$this->shipment->id, $this->driver->id, 25.0], [$second->id, $other->id, 40.0]]);
    });

    // تاسك 198 — مدير الفرع يعدّل التسوية من /expenses مباشرةً، والمحاسب يعرض فقط.
    it('lets the branch admin edit the settlement expense, driver included, without touching its link', function () {
        ($this->settle)('cash_drawer');
        $expense = Expense::firstOrFail();
        $otherDriver = DeliveryProvider::factory()->create(['branch_id' => $this->branch->id, 'name' => 'أبو عبد الرحمن']);
        $payload = [
            'expense_category_id' => $this->category->id,
            'qty' => 1,
            'unit_price' => 40,
            'paid_from' => 'company_transfer',
            'bank_account_id' => $this->bankAccount->id,
            'delivery_provider_id' => $otherDriver->id,
            // الربط ثابت: ما يُرسل هنا يُتجاهل.
            'invoices' => [],
            'date' => $expense->date->toDateString(),
        ];

        $this->actingAs($this->accountant)->put(route('expenses.update', $expense), $payload)->assertForbidden();
        $this->actingAs($this->accountant)->get(route('expenses.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.canUpdate', false)->where('deliveryProviders', []));

        $this->actingAs($this->admin)->put(route('expenses.update', $expense), $payload)->assertSessionHasNoErrors();

        $expense->refresh();
        expect((float) $expense->total)->toBe(40.0)
            ->and($expense->delivery_provider_id)->toBe($otherDriver->id)
            ->and($expense->supplier_name)->toBe('أبو عبد الرحمن')
            ->and($expense->service_invoice_shipment_id)->toBe($this->shipment->id)
            ->and($expense->invoices->pluck('id')->all())->toBe([$this->invoice->id]);

        // لا يُفرَّغ السائق، ولا يصير مصروفٌ عادي تسويةً.
        $this->actingAs($this->admin)->put(route('expenses.update', $expense), [...$payload, 'delivery_provider_id' => null])
            ->assertSessionHasErrors('delivery_provider_id');
        $plain = Expense::factory()->create(['branch_id' => $this->branch->id, 'expense_category_id' => $this->category->id]);
        $this->actingAs($this->admin)->put(route('expenses.update', $plain), $payload)
            ->assertSessionHasErrors('delivery_provider_id');
    });

    it('locks the settlement expense on the expenses screen and lets the branch admin cancel it', function () {
        ($this->settle)('cash_drawer');
        $expense = Expense::firstOrFail();

        // الحذف من /expenses يمرّ بإلغاء التسوية (بسبب) لا بالحذف العادي.
        $this->actingAs($this->admin)->delete(route('expenses.destroy', $expense))->assertForbidden();

        $this->actingAs($this->admin)
            ->delete(route('shipping.settlements.destroy', $expense), ['reason' => 'مبلغ خاطئ'])
            ->assertRedirect();

        $this->assertSoftDeleted($expense);
        ($this->settle)('cash_drawer', 30)->assertSessionHasNoErrors();
    });

    // تاسك 152 — «تسليم مبلغ التوصيل» عند المحاسب، على فرعه فقط.
    it('lets the branch accountant settle and cancel a delivery', function () {
        $this->shipment->update(['fee' => 25]);

        $this->actingAs($this->accountant)
            ->post(route('shipping.deliveries.settle', $this->shipment), [
                'amount' => 25,
                'paid_from' => 'cash_drawer',
                'expense_category_id' => $this->category->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $expense = Expense::firstOrFail();
        expect($expense->user_id)->toBe($this->accountant->id)
            ->and($expense->isApproved())->toBeTrue();

        $this->actingAs($this->accountant)
            ->delete(route('shipping.settlements.destroy', $expense), ['reason' => 'مبلغ خاطئ'])
            ->assertRedirect();

        $this->assertSoftDeleted($expense);
    });

    // المحاسب لا يعدّل مبلغ التوصيل — يُسلَّم أجر الطلب، والمجاني يسوّيه المدير.
    it('locks the accountant to the shipment fee', function () {
        $this->shipment->update(['fee' => 25]);
        $payload = ['amount' => 999, 'paid_from' => 'cash_drawer', 'expense_category_id' => $this->category->id];

        $this->actingAs($this->accountant)
            ->post(route('shipping.deliveries.settle', $this->shipment), $payload)
            ->assertSessionHasNoErrors();

        expect((float) Expense::firstOrFail()->total)->toBe(25.0);
    });

    it('leaves a free delivery to the branch admin', function () {
        $this->actingAs($this->accountant)
            ->post(route('shipping.deliveries.settle', $this->shipment), [
                'amount' => 25,
                'paid_from' => 'cash_drawer',
                'expense_category_id' => $this->category->id,
            ])
            ->assertSessionHasErrors('amount');

        expect(Expense::count())->toBe(0);
    });

    it('forbids a foreign-branch accountant and an employee', function () {
        $foreignAccountant = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $foreignAccountant->addRole(Roles::ACCOUNTANT->value);

        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->shipment->update(['fee' => 25]);
        $payload = ['amount' => 25, 'paid_from' => 'cash_drawer', 'expense_category_id' => $this->category->id];

        $this->actingAs($foreignAccountant)->post(route('shipping.deliveries.settle', $this->shipment), $payload)->assertForbidden();
        $this->actingAs($employee)->post(route('shipping.deliveries.settle', $this->shipment), $payload)->assertForbidden();

        expect(Expense::count())->toBe(0);
    });

    it('settles from the invoice page and shows the settlement there (task 190)', function () {
        $invoiceUrl = route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]);
        $this->shipment->update(['fee' => 25]);

        $this->actingAs($this->accountant)
            ->get($invoiceUrl)
            ->assertInertia(fn ($page) => $page
                ->where('settleForm.categories.0.id', $this->category->id)
                ->where('invoice.shipments.0.settlement', null));

        $this->from($invoiceUrl)
            ->post(route('shipping.deliveries.settle', $this->shipment), [
                'amount' => 25, 'paid_from' => 'cash_drawer', 'expense_category_id' => $this->category->id,
            ])
            ->assertRedirect($invoiceUrl);

        expect(Expense::sole()->delivery_provider_id)->toBe($this->driver->id);

        $this->get($invoiceUrl)
            ->assertInertia(fn ($page) => $page->where('invoice.shipments.0.settlement.amount', 25));

        // صاحب الفاتورة يراها بلا زرّ التسوية ولا مبلغها.
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $this->invoice->update(['user_id' => $employee->id]);

        $this->actingAs($employee)
            ->get($invoiceUrl)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('settleForm', null)
                ->where('invoice.shipments.0.settlement', null));
    });
});
