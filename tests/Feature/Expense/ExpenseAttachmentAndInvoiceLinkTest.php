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

    // تاسك 149: نافذة «تسجيل مصروف» من الفاتورة — لمن يسجّل المصروفات وحده.
    it('passes the expense form to whoever may record expenses, not to the employee', function () {
        $show = route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]);

        $this->actingAs($this->accountant)->get($show)->assertInertia(fn ($page) => $page
            ->where('expenseForm.categories.0.name', 'توصيل')
            ->where('expenseForm.branches', null));

        // تاسك 157: صاحب الفاتورة يفتح النافذة نفسها طلباً يقبله المحاسب.
        $this->actingAs($this->employee)->get($show)
            ->assertInertia(fn ($page) => $page->where('expenseForm.isRequest', true));
    });

    // تاسك 161 — مرفقات القائمة المصفّاة في ملف ZIP واحد.
    it('zips the attachments of the filtered list only', function () {
        $other = ExpenseCategory::factory()->create(['name' => 'أحبار']);
        $expense = fn (array $attrs, bool $file = true) => tap(
            Expense::factory()->create(['branch_id' => $this->branch->id, 'date' => today(), ...$attrs]),
            fn (Expense $e) => $file && $e->addMedia(UploadedFile::fake()->image('r.jpg'))->toMediaCollection(Expense::ATTACHMENT),
        );

        $a = $expense(['expense_category_id' => $this->category->id, 'supplier_name' => 'مطبعة النور']);
        $b = $expense(['expense_category_id' => $this->category->id, 'supplier_name' => null]);
        $expense(['expense_category_id' => $this->category->id], file: false);
        $expense(['expense_category_id' => $other->id]);

        $this->actingAs($this->accountant)->get(route('expenses.index'))
            ->assertInertia(fn ($page) => $page->where('attachmentsCount', 3));

        $response = $this->actingAs($this->accountant)
            ->get(route('expenses.attachments', ['expense_category_id' => $this->category->id]))
            ->assertOk();

        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();
        $day = today()->format('Y-m-d');
        expect($names)->toEqualCanonicalizing(["{$day}-مطبعة النور-{$a->id}.jpg", "{$day}-توصيل-{$b->id}.jpg"]);

        $this->actingAs($this->accountant)->get(route('expenses.attachments', ['expense_category_id' => 999]))
            ->assertRedirect()->assertSessionHas('error');
        $this->actingAs($this->employee)->get(route('expenses.attachments'))->assertForbidden();
    });
});
