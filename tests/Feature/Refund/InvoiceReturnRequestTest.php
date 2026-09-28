<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\InvoiceReturnRequest;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** فاتورة خدمة مدفوعة (1150 شاملة) للموظف، بعمولة 100 غير مدفوعة. */
function paidInvoiceForReturnRequest(Branch $branch, User $employee): ServiceInvoice
{
    $invoice = ServiceInvoice::create([
        'invoice_number' => 'SINV-RR-00001',
        'branch_id' => $branch->id,
        'user_id' => $employee->id,
        'subtotal' => 1000,
        'vat_pct' => 15,
        'vat_amount' => 150,
        'total_amount' => 1150,
        'employee_commission' => 100,
        'status' => InvoiceStatusEnum::PAID,
        'paid_at' => now(),
    ]);

    $line = $invoice->lines()->create([
        'branch_service_id' => null,
        'service_name' => 'طباعة',
        'qty' => 1,
        'unit_price' => 1000,
        'discount_pct' => 0,
        'subtotal' => 1000,
        'commission_pct' => 10,
        'commission_amount' => 100,
    ]);

    CommissionLedger::create([
        'user_id' => $employee->id,
        'branch_id' => $branch->id,
        'invoice_line_id' => $line->id,
        'invoice_line_type' => ServiceInvoiceLine::class,
        'amount' => 100,
        'is_tahazir' => false,
        'earned_at' => now(),
    ]);

    return $invoice->fresh();
}

describe('طلبات الاسترجاع (تاسك 135)', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->invoice = paidInvoiceForReturnRequest($this->branch, $this->employee);
        $this->method = paymentMethodId($this->branch->id);
    });

    function raiseReturnRequest(): InvoiceReturnRequest
    {
        test()->actingAs(test()->employee)
            ->post(route('pos.service.return', test()->invoice), ['reason' => 'العميل غيّر رأيه'])
            ->assertSessionHasNoErrors();

        return InvoiceReturnRequest::sole();
    }

    it('raises a request instead of returning, and notifies the approvers', function () {
        $request = raiseReturnRequest();

        expect($request->status->value)->toBe('pending')
            ->and($request->reason)->toBe('العميل غيّر رأيه')
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatusEnum::PAID)
            ->and(Refund::count())->toBe(0)
            ->and((float) CommissionLedger::sum('amount'))->toBe(100.00)
            ->and($this->admin->notifications()->count())->toBe(1)
            ->and($this->accountant->notifications()->count())->toBe(1);
    });

    it('refuses a second open request on the same invoice', function () {
        raiseReturnRequest();

        $this->post(route('pos.service.return', $this->invoice))->assertForbidden();

        expect(InvoiceReturnRequest::count())->toBe(1);
    });

    it('lets the accountant approve with a refund method, returning the invoice once', function () {
        $request = raiseReturnRequest();

        $this->actingAs($this->accountant)
            ->post(route('refunds.requests.approve', $request), ['payment_method_id' => $this->method])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $refund = Refund::sole();

        expect($this->invoice->refresh()->status)->toBe(InvoiceStatusEnum::RETURNED)
            ->and($request->status->value)->toBe('completed')
            ->and($request->decided_by)->toBe($this->accountant->id)
            ->and($request->refund_id)->toBe($refund->id)
            ->and((float) $refund->amount)->toBe(1150.00)
            ->and($refund->payment_method_id)->toBe($this->method)
            ->and($refund->reason)->toBe('العميل غيّر رأيه')
            ->and((float) CommissionLedger::sum('amount'))->toBe(0.00)
            ->and($this->employee->notifications()->count())->toBe(1);

        // لا اعتماد ثانٍ لطلبٍ أُتمّ.
        $this->post(route('refunds.requests.approve', $request), ['payment_method_id' => $this->method])
            ->assertSessionHasErrors('status');
        expect(Refund::count())->toBe(1);
    });

    it('lets the accountant approve a partial amount, keeping the invoice paid and closing the request', function () {
        $request = raiseReturnRequest();

        $this->actingAs($this->accountant)
            ->post(route('refunds.requests.approve', $request), ['payment_method_id' => $this->method, 'amount' => 575])
            ->assertSessionHasNoErrors();

        $refund = Refund::sole();

        expect($this->invoice->refresh()->status)->toBe(InvoiceStatusEnum::PAID)
            ->and($request->refresh()->status->value)->toBe('completed')
            ->and($request->refund_id)->toBe($refund->id)
            ->and((float) $refund->amount)->toBe(575.00)
            ->and($refund->user_id)->toBe($this->accountant->id)
            ->and((float) CommissionLedger::sum('amount'))->toBe(50.00);
    });

    it('refuses a partial amount above what was collected', function () {
        $request = raiseReturnRequest();

        $this->actingAs($this->accountant)
            ->post(route('refunds.requests.approve', $request), ['payment_method_id' => $this->method, 'amount' => 1150.01])
            ->assertSessionHasErrors('amount');

        expect($request->refresh()->status->value)->toBe('pending')
            ->and(Refund::count())->toBe(0);
    });

    it('requires the refund method on approval', function () {
        $request = raiseReturnRequest();

        $this->actingAs($this->admin)
            ->post(route('refunds.requests.approve', $request))
            ->assertSessionHasErrors('payment_method_id');

        expect($request->refresh()->status->value)->toBe('pending')
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatusEnum::PAID);
    });

    it('rejects with a reason that reaches the employee and leaves the invoice untouched', function () {
        $request = raiseReturnRequest();

        $this->actingAs($this->admin)
            ->post(route('refunds.requests.reject', $request), ['rejection_reason' => 'العمل سُلِّم'])
            ->assertSessionHasNoErrors();

        expect($request->refresh()->status->value)->toBe('rejected')
            ->and($request->rejection_reason)->toBe('العمل سُلِّم')
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatusEnum::PAID)
            ->and(Refund::count())->toBe(0)
            ->and($this->employee->notifications()->sole()->data['body'])->toContain('العمل سُلِّم');

        // بعد الرفض يستطيع الموظف رفع طلبٍ جديد.
        $this->actingAs($this->employee)->post(route('pos.service.return', $this->invoice))->assertSessionHasNoErrors();
        expect(InvoiceReturnRequest::count())->toBe(2);
    });

    it('keeps the employee, the auditor and other branches from deciding', function () {
        $request = raiseReturnRequest();

        $this->actingAs($this->employee)
            ->post(route('refunds.requests.approve', $request), ['payment_method_id' => $this->method])
            ->assertForbidden();

        $auditor = User::factory()->create(['branch_id' => $this->branch->id]);
        $auditor->addRole(Roles::AUDITOR->value);
        $this->actingAs($auditor)->get(route('refunds.requests.index'))->assertOk();
        $this->actingAs($auditor)
            ->post(route('refunds.requests.reject', $request), ['rejection_reason' => 'x'])
            ->assertForbidden();

        $otherAccountant = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $otherAccountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($otherAccountant)
            ->post(route('refunds.requests.approve', $request), ['payment_method_id' => $this->method])
            ->assertForbidden();

        expect($request->refresh()->status->value)->toBe('pending');
    });

    it('lists the branch requests with the approve action for the accountant', function () {
        raiseReturnRequest();

        $this->actingAs($this->accountant)
            ->get(route('refunds.requests.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('refunds/requests')
                ->where('items.data.0.invoiceNumber', 'SINV-RR-00001')
                ->where('items.data.0.amount', 1150)
                ->where('items.data.0.canDecide', true));
    });

    it('still returns an uncollected due invoice straight away', function () {
        $this->invoice->update(['status' => InvoiceStatusEnum::DUE, 'paid_at' => null]);

        $this->actingAs($this->employee)
            ->post(route('pos.service.return', $this->invoice))
            ->assertRedirect(route('invoices.index'));

        expect($this->invoice->refresh()->status)->toBe(InvoiceStatusEnum::RETURNED)
            ->and(InvoiceReturnRequest::count())->toBe(0);
    });
});
