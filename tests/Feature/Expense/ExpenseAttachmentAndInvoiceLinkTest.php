<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * تاسك 112 — مرفق المصروف على القرص الخاص، وربطه بفاتورة خدمات من فرعه يظهر
 * في الجهتين: رقم الطلب على المصروف، والمصروف على الفاتورة لمن يدير المصروفات.
 */
function linkableInvoice(Branch $branch, User $user, string $number): ServiceInvoice
{
    return ServiceInvoice::create([
        'invoice_number' => $number,
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 13.04,
        'total_amount' => 100,
        'employee_commission' => 0,
        'status' => InvoiceStatusEnum::PAID,
        'paid_at' => now(),
    ]);
}

describe('Expense attachment & invoice link', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->otherAdmin = User::factory()->create();
        $this->otherAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->otherBranch = Branch::factory()->create(['owner_id' => $this->otherAdmin->id]);
        $this->otherAdmin->update(['branch_id' => $this->otherBranch->id]);

        $this->category = ExpenseCategory::factory()->create(['name' => 'توصيل']);
        $this->invoice = linkableInvoice($this->branch, $this->employee, 'SINV-018-00025');

        $this->payload = [
            'expense_category_id' => $this->category->id,
            'qty' => 1,
            'unit_price' => 25,
            'paid_from' => 'cash_drawer',
            'date' => today()->toDateString(),
        ];
    });

    it('stores a linked expense with its attachment and serves it to its branch only', function () {
        $this->actingAs($this->accountant)->post(route('expenses.store'), [
            ...$this->payload,
            'service_invoice_id' => $this->invoice->id,
            'attachment' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n%%EOF"),
        ])->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();
        expect($expense->service_invoice_id)->toBe($this->invoice->id)
            ->and($expense->attachment()?->file_name)->toBe('receipt.pdf');

        $this->actingAs($this->accountant)->get(route('expenses.attachment', $expense))->assertOk();
        $this->actingAs($this->otherAdmin)->get(route('expenses.attachment', $expense))->assertForbidden();
    });

    it('rejects linking an invoice from another branch', function () {
        $theirs = linkableInvoice($this->otherBranch, $this->otherAdmin, 'SINV-099-00001');

        $this->actingAs($this->accountant)
            ->post(route('expenses.store'), [...$this->payload, 'service_invoice_id' => $theirs->id])
            ->assertSessionHasErrors('service_invoice_id');
    });

    it('searches only the branch invoices', function () {
        linkableInvoice($this->otherBranch, $this->otherAdmin, 'SINV-018-00999');

        $this->actingAs($this->accountant)
            ->getJson(route('expenses.invoice-options', ['q' => 'SINV-018']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoiceNumber', 'SINV-018-00025');
    });

    it('shows the linked expense on the invoice to reviewers, not to the employee or the print sheet', function () {
        Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $this->category->id,
            'user_id' => $this->accountant->id,
            'service_invoice_id' => $this->invoice->id,
            'total' => 25,
        ]);
        $show = route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]);

        $this->actingAs($this->accountant)->get($show)->assertInertia(fn ($page) => $page
            ->has('invoice.linkedExpenses', 1)
            ->where('invoice.linkedExpenses.0.categoryName', 'توصيل')
            ->where('invoice.linkedExpenses.0.total', 25));

        $this->actingAs($this->employee)->get($show)
            ->assertInertia(fn ($page) => $page->has('invoice.linkedExpenses', 0));

        $this->actingAs($this->accountant)
            ->get(route('invoices.print', ['type' => 'service', 'id' => $this->invoice->id]))
            ->assertInertia(fn ($page) => $page->has('invoice.linkedExpenses', 0));
    });
});
