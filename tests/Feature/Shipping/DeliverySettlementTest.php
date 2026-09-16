<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
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
 * تاسك 111 — «استلام مبلغ التوصيل»: مصروفٌ معتمد مربوطٌ بالطلب وسائقه. النقدي
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

        // طلبٌ «مجاني» غير مسدَّد — السائق يُدفع له ولو لم يدفع العميل.
        $this->invoice = ServiceInvoice::create([
            'invoice_number' => 'SINV-018-00025',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'subtotal' => 100,
            'shipping_fee' => 0,
            'shipping_provider_id' => $this->driver->id,
            'vat_pct' => 15,
            'vat_amount' => 13.04,
            'total_amount' => 100,
            'employee_commission' => 0,
            'status' => InvoiceStatusEnum::DUE,
        ]);

        $this->settle = fn (string $source, $amount = 25) => $this->actingAs($this->admin)
            ->post(route('shipping.deliveries.settle', $this->invoice), [
                'amount' => $amount,
                'paid_from' => $source,
                'expense_category_id' => $this->category->id,
            ]);
    });

    it('records a cash settlement as an approved linked expense taken off the drawer cash', function () {
        PaymentMethod::factory()->cash()->create();

        ($this->settle)('cash_drawer')->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();
        expect($expense->service_invoice_id)->toBe($this->invoice->id)
            ->and($expense->delivery_provider_id)->toBe($this->driver->id)
            ->and((float) $expense->total)->toBe(25.0)
            ->and($expense->isApproved())->toBeTrue()
            ->and($this->invoice->fresh()->status)->toBe(InvoiceStatusEnum::DUE);

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

    it('refuses a second settlement and a zero amount', function () {
        ($this->settle)('cash_drawer', 0)->assertSessionHasErrors('amount');
        ($this->settle)('cash_drawer')->assertSessionHasNoErrors();
        ($this->settle)('cash_drawer')->assertSessionHasErrors('amount');

        expect(Expense::count())->toBe(1);
    });

    it('locks the settlement expense on the expenses screen and lets the branch admin cancel it', function () {
        ($this->settle)('cash_drawer');
        $expense = Expense::firstOrFail();

        $this->actingAs($this->admin)->delete(route('expenses.destroy', $expense))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('shipping.deliveries.settle', $this->invoice), [])->assertForbidden();

        $this->actingAs($this->admin)
            ->delete(route('shipping.settlements.destroy', $expense), ['reason' => 'مبلغ خاطئ'])
            ->assertRedirect();

        $this->assertSoftDeleted($expense);
        ($this->settle)('cash_drawer', 30)->assertSessionHasNoErrors();
    });
});
