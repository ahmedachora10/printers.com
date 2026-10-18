<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * تاسك 187 — الحساب البنكي للتحويل، المرفقات المتعددة، والمصروف الواحد على
 * أكثر من فاتورة بحصصٍ مجموعها إجمالي المصروف.
 */
describe('Expense bank accounts, attachments & invoice split', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);

        $this->category = ExpenseCategory::factory()->create();
        $this->account = BankAccount::create(['branch_id' => $this->branch->id, 'name' => 'الرئيسي', 'bank_name' => 'الراجحي', 'iban_last4' => '1234']);

        $this->invoice = fn (string $number) => ServiceInvoice::create([
            'invoice_number' => $number,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'subtotal' => 100, 'vat_pct' => 15, 'vat_amount' => 13.04, 'total_amount' => 100,
            'employee_commission' => 0,
            'status' => InvoiceStatusEnum::PAID,
            'paid_at' => now(),
        ]);

        $this->payload = [
            'expense_category_id' => $this->category->id,
            'qty' => 1,
            'unit_price' => 100,
            'paid_from' => 'cash_drawer',
            'date' => today()->toDateString(),
        ];

        $this->actingAs($this->admin);
    });

    it('requires a branch bank account for a transfer and refuses one on cash', function () {
        $foreign = BankAccount::create(['branch_id' => Branch::factory()->create()->id, 'name' => 'فرع آخر']);
        $transfer = [...$this->payload, 'paid_from' => 'company_transfer'];

        $this->post(route('expenses.store'), $transfer)->assertSessionHasErrors('bank_account_id');
        $this->post(route('expenses.store'), [...$transfer, 'bank_account_id' => $foreign->id])->assertSessionHasErrors('bank_account_id');
        $this->post(route('expenses.store'), [...$this->payload, 'bank_account_id' => $this->account->id])->assertSessionHasErrors('bank_account_id');

        $this->post(route('expenses.store'), [...$transfer, 'bank_account_id' => $this->account->id])->assertSessionHasNoErrors();

        $this->get(route('expenses.index', ['bank_account_id' => $this->account->id]))
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.bankAccountName', 'الراجحي — الرئيسي ••1234'));
    });

    it('shows and filters the bank account in the expense report', function () {
        Expense::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id, 'date' => today(), 'paid_from' => 'company_transfer', 'bank_account_id' => $this->account->id]);
        Expense::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id, 'date' => today(), 'paid_from' => 'cash_drawer']);

        $this->get(route('reports.expenses', ['bank_account' => $this->account->id]))
            ->assertInertia(fn ($page) => $page
                ->has('expenses', 1)
                ->where('expenses.0.bankAccountName', 'الراجحي — الرئيسي ••1234'));
    });

    it('splits an expense over two invoices only when the shares add up to the total', function () {
        $a = ($this->invoice)('SINV-1');
        $b = ($this->invoice)('SINV-2');

        $this->post(route('expenses.store'), [...$this->payload, 'invoices' => [['id' => $a->id, 'amount' => 60], ['id' => $b->id, 'amount' => 30]]])
            ->assertSessionHasErrors('invoices');

        $this->post(route('expenses.store'), [...$this->payload, 'invoices' => [['id' => $a->id, 'amount' => 60], ['id' => $b->id, 'amount' => 40]]])
            ->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();
        expect($expense->invoices->pluck('pivot.amount', 'id')->map(fn ($v) => (float) $v)->all())->toBe([$a->id => 60.0, $b->id => 40.0]);

        // الفاتورة تعرض حصّتها وإجمالي المصروف بجانبها.
        $this->get(route('invoices.show', ['type' => 'service', 'id' => $b->id]))
            ->assertInertia(fn ($page) => $page
                ->where('invoice.linkedExpenses.0.amount', 40)
                ->where('invoice.linkedExpenses.0.total', 100));
    });

    it('gives a single linked invoice the whole amount whatever was sent', function () {
        $a = ($this->invoice)('SINV-1');

        $this->post(route('expenses.store'), [...$this->payload, 'invoices' => [['id' => $a->id, 'amount' => 5]]])
            ->assertSessionHasNoErrors();

        expect((float) Expense::firstOrFail()->invoices->first()->pivot->amount)->toBe(100.0);
    });

    it('keeps several attachments, removes the ticked ones and logs a relink after approval', function () {
        $a = ($this->invoice)('SINV-1');
        $b = ($this->invoice)('SINV-2');
        $file = fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF");

        $this->post(route('expenses.store'), [...$this->payload, 'invoices' => [['id' => $a->id]], 'attachments' => [$file('a.pdf'), $file('b.pdf'), $file('c.pdf')]])
            ->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();
        expect($expense->attachments())->toHaveCount(3);
        $expense->forceFill(['approved_at' => now(), 'approved_by' => $this->admin->id])->save();

        $this->post(route('expenses.update', $expense), [
            ...$this->payload,
            '_method' => 'put',
            'remove_attachment_ids' => [$expense->attachments()->first()->id],
            'attachments' => [$file('d.pdf')],
            'invoices' => [['id' => $a->id, 'amount' => 50], ['id' => $b->id, 'amount' => 50]],
        ])->assertSessionHasNoErrors();

        $expense = $expense->fresh();
        expect($expense->attachments()->pluck('file_name')->sort()->values()->all())->toBe(['b.pdf', 'c.pdf', 'd.pdf'])
            ->and($expense->invoices)->toHaveCount(2);

        $this->get(route('expenses.index', ['range' => 'all']))
            ->assertInertia(fn ($page) => $page
                ->has('items.data.0.attachments', 3)
                ->where('items.data.0.history.0.old.invoices', 'SINV-1 (100.00)')
                ->where('items.data.0.history.0.new.invoices', 'SINV-1 (50.00)، SINV-2 (50.00)'));
    });

    it('moves the old single invoice link into the pivot', function () {
        $migration = require database_path('migrations/2026_10_18_110000_create_expense_service_invoice_table.php');
        $a = ($this->invoice)('SINV-1');
        $expense = Expense::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id, 'total' => 75]);
        $expense->invoices()->attach($a->id, ['amount' => 75]);

        $migration->down();
        expect(DB::table('expenses')->where('id', $expense->id)->value('service_invoice_id'))->toBe($a->id);

        $migration->up();
        expect(DB::table('expense_service_invoice')->where('expense_id', $expense->id)->first())
            ->service_invoice_id->toBe($a->id)
            ->amount->toEqual(75);
    });

    it('lets a branch admin manage only their own bank accounts', function () {
        $other = BankAccount::create(['branch_id' => Branch::factory()->create()->id, 'name' => 'فرع آخر']);

        $this->post(route('bank-accounts.store'), ['name' => 'الاحتياطي', 'iban_last4' => '9876', 'branch_id' => $other->branch_id])
            ->assertSessionHasNoErrors();
        expect(BankAccount::where('name', 'الاحتياطي')->value('branch_id'))->toBe($this->branch->id);

        $this->delete(route('bank-accounts.destroy', $other))->assertForbidden();
        $this->delete(route('bank-accounts.destroy', $this->account))->assertRedirect();
        expect($this->account->fresh()->trashed())->toBeTrue();
    });
});
