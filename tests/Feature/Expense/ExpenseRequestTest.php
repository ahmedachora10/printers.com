<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Notifications\ExpenseRequestNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * تاسك 157 — الموظف يطلب مصروفاً على فاتورته، ولا يُحسب في أي مجموع حتى يقبله
 * المحاسب. القبول غير الاعتماد: المقبول يعود مصروفاً عادياً يمرّ بالاعتماد.
 */
describe('Expense requests', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->category = ExpenseCategory::factory()->create(['name' => 'خامات']);

        $this->invoiceOf = fn (User $user) => ServiceInvoice::create([
            'invoice_number' => 'SINV-'.fake()->unique()->numberBetween(1, 999999),
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'subtotal' => 100, 'vat_pct' => 15, 'vat_amount' => 13.04, 'total_amount' => 100,
            'employee_commission' => 0,
            'status' => InvoiceStatusEnum::DUE,
        ]);
        $this->invoice = ($this->invoiceOf)($this->employee);

        $this->payload = fn (ServiceInvoice $invoice) => [
            'expense_category_id' => $this->category->id,
            'qty' => 1,
            'unit_price' => 40,
            'paid_from' => 'cash_drawer',
            'date' => today()->toDateString(),
            'service_invoice_id' => $invoice->id,
        ];

        $this->submit = function () {
            $this->actingAs($this->employee)->post(route('expenses.request'), ($this->payload)($this->invoice))->assertRedirect();

            return Expense::latest('id')->first();
        };
    });

    it('lets the employee request an expense on their invoice and notifies the branch', function () {
        $expense = ($this->submit)();

        expect($expense->requested_by)->toBe($this->employee->id)
            ->and($expense->isPendingRequest())->toBeTrue()
            ->and((float) $expense->total)->toBe(40.0);

        Notification::assertSentTo([$this->accountant, $this->branchAdmin], ExpenseRequestNotification::class);
    });

    it('forbids a request on another employee\'s invoice', function () {
        $colleague = User::factory()->create(['branch_id' => $this->branch->id]);
        $colleague->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->employee)
            ->post(route('expenses.request'), ($this->payload)(($this->invoiceOf)($colleague)))
            ->assertForbidden();

        expect(Expense::count())->toBe(0);
    });

    it('keeps a pending request out of every total until the accountant accepts it', function () {
        $expense = ($this->submit)();

        $this->actingAs($this->accountant)->get(route('expenses.index'))
            ->assertInertia(fn ($page) => $page->where('periodTotal', 0)->has('items.data', 1)->where('items.data.0.pendingRequest', true));
        $this->actingAs($this->branchAdmin)->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page->where('totals.expenses', 0));
        $this->actingAs($this->branchAdmin)->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.purchases', 0));
        $this->actingAs($this->branchAdmin)->get(route('reports.expenses'))
            ->assertInertia(fn ($page) => $page->where('totals.expenseCount', 0));

        $this->actingAs($this->accountant)->post(route('expenses.accept', $expense))->assertRedirect();

        expect($expense->fresh()->accepted_by)->toBe($this->accountant->id);
        Notification::assertSentTo($this->employee, ExpenseRequestNotification::class);

        $this->actingAs($this->accountant)->get(route('expenses.index'))
            ->assertInertia(fn ($page) => $page->where('periodTotal', 40));
        $this->actingAs($this->branchAdmin)->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page->where('totals.expenses', 40));
    });

    it('cannot be approved before it is accepted', function () {
        $expense = ($this->submit)();

        $this->actingAs($this->branchAdmin)->post(route('expenses.approve', $expense))->assertForbidden();
        $this->actingAs($this->branchAdmin)->post(route('expenses.approve-all', ['range' => 'all']));

        expect($expense->fresh()->isApproved())->toBeFalse();
    });

    it('rejects with a reason: soft-deleted and the employee is told why', function () {
        $expense = ($this->submit)();

        $this->actingAs($this->accountant)->post(route('expenses.reject', $expense), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->accountant)->post(route('expenses.reject', $expense), ['reason' => 'لا يوجد إيصال'])
            ->assertRedirect();

        expect($expense->fresh()->trashed())->toBeTrue();
        Notification::assertSentTo($this->employee, ExpenseRequestNotification::class,
            fn ($n) => str_contains($n->toArray($this->employee)['body'], 'لا يوجد إيصال'));
    });

    it('shows the employee the request form and their pending request on the invoice', function () {
        ($this->submit)();

        $this->actingAs($this->employee)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]))
            ->assertInertia(fn ($page) => $page
                ->where('expenseForm.isRequest', true)
                ->has('invoice.linkedExpenses', 1)
                ->where('invoice.linkedExpenses.0.pendingRequest', true)
                ->where('invoice.linkedExpenses.0.attachmentUrl', null));
    });
});
