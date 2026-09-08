<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * تاسك 88 — عمود «طريقة الدفع» وزرّ الاعتماد السريع في قائمة الفواتير
 * (/invoices). الاعتماد يمرّ بنفس المسار والسياسة القائمَين، والصفّ يحمل ما
 * ينقص الفاتورة قبله حتى لا يفشل الطلب صامتاً.
 */
function listInvoice(int $branchId, int $userId, array $overrides = []): ServiceInvoice
{
    return ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-LST-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branchId,
        'user_id' => $userId,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 13.04,
        'total_amount' => 100,
        'employee_commission' => 0,
        'status' => InvoiceStatusEnum::DUE,
    ], $overrides));
}

/** صفّ فاتورةٍ بعينها من حمولة القائمة. */
function listRow(array $items, string $invoiceNumber): ?array
{
    return collect($items)->firstWhere('invoiceNumber', $invoiceNumber);
}

describe('Invoice list — payment method column and quick approval', function () {
    beforeEach(function () {
        $this->withoutVite();
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->method = PaymentMethod::factory()->create(['name' => 'نقد']);
    });

    // ── العمود ───────────────────────────────────────────────────

    it('carries the payment method name into the list payload', function () {
        $invoice = listInvoice($this->branch->id, $this->employee->id, ['payment_method_id' => $this->method->id]);

        $this->actingAs($this->accountant)
            ->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page
                ->where('items.data.0.invoiceNumber', $invoice->invoice_number)
                ->where('items.data.0.paymentMethodName', 'نقد'));
    });

    it('leaves the payment method null when none was chosen', function () {
        listInvoice($this->branch->id, $this->employee->id);

        $this->actingAs($this->accountant)
            ->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.paymentMethodName', null));
    });

    // ── الزرّ ────────────────────────────────────────────────────

    it('lets the accountant approve a due service invoice straight from the list', function () {
        $invoice = listInvoice($this->branch->id, $this->employee->id, ['payment_method_id' => $this->method->id]);

        $this->actingAs($this->accountant)
            ->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page
                ->where('items.data.0.canApprove', true)
                ->where('items.data.0.approveBlockedReason', null));

        $this->actingAs($this->accountant)
            ->patch(route('invoices.service.pay', $invoice))
            ->assertRedirect();

        expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::PAID);
    });

    it('names the missing payment method instead of offering a doomed approval', function () {
        listInvoice($this->branch->id, $this->employee->id);

        $this->actingAs($this->accountant)
            ->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page
                ->where('items.data.0.canApprove', true)
                ->where('items.data.0.approveBlockedReason', 'method'));
    });

    it('names the missing transfer receipt when the method demands one', function () {
        $transfer = PaymentMethod::factory()->requiresAttachment()->create(['name' => 'تحويل بنكي']);
        listInvoice($this->branch->id, $this->employee->id, ['payment_method_id' => $transfer->id]);

        $this->actingAs($this->accountant)
            ->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.approveBlockedReason', 'receipt'));
    });

    it('withholds the approve control from the employee who raised the invoice', function () {
        $invoice = listInvoice($this->branch->id, $this->employee->id, ['payment_method_id' => $this->method->id]);

        $this->actingAs($this->employee)
            ->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.canApprove', false));

        // والميدلوير يبقى الحكم النهائي على الطلب المباشر.
        $this->actingAs($this->employee)
            ->patch(route('invoices.service.pay', $invoice))
            ->assertForbidden();

        expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::DUE);
    });

    it('never offers approval on a product invoice', function () {
        ProductInvoice::create([
            'invoice_number' => 'INV-LST-001',
            'branch_id' => $this->branch->id,
            'user_id' => $this->employee->id,
            'payment_method_id' => $this->method->id,
            'subtotal' => 50,
            'vat_pct' => 15,
            'vat_amount' => 6.52,
            'total_amount' => 50,
            'status' => InvoiceStatusEnum::DUE,
        ]);

        $this->actingAs($this->accountant)
            ->get(route('invoices.index', ['type' => 'product']))
            ->assertInertia(fn ($page) => $page
                ->where('items.data.0.canApprove', false)
                ->where('items.data.0.paymentMethodName', 'نقد'));
    });

    it('never offers approval on an already paid invoice', function () {
        listInvoice($this->branch->id, $this->employee->id, [
            'payment_method_id' => $this->method->id,
            'status' => InvoiceStatusEnum::PAID,
        ]);

        $this->actingAs($this->accountant)
            ->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.canApprove', false));
    });
});
