<?php

use App\Enums\CustomerTypeEnum;
use App\Enums\LoyaltyTransactionTypeEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Notifications\ServiceInvoiceReviewedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * Build a due service invoice with one line, mirroring what the employee POS
 * produces. A due invoice carries no commission ledger row — commission is
 * written only when an accountant approves (pays) the invoice.
 */
function makeDueInvoice(array $overrides = [], ?int $commission = 10): ServiceInvoice
{
    $invoice = ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-TEST-'.fake()->unique()->numerify('#####'),
        'branch_id' => test()->branch->id,
        'user_id' => test()->employee->id,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 15,
        'total_amount' => 115,
        'employee_commission' => $commission,
        'status' => 'due',
    ], $overrides));

    $invoice->lines()->create([
        'service_name' => 'خدمة اختبار',
        'qty' => 1,
        'unit_price' => 100,
        'discount_pct' => 0,
        'subtotal' => 100,
        'commission_pct' => 10,
        'commission_amount' => $commission,
        'is_tahazir' => false,
    ]);

    return $invoice;
}

describe('Service invoice review', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->actingAs($this->accountant);
    });

    it('shows the due invoice review queue to an accountant', function () {
        makeDueInvoice();

        $this->get(route('invoices.service.review'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('invoices/review')->has('invoices', 1));
    });

    it('forbids an employee from the review queue', function () {
        $this->actingAs($this->employee);

        $this->get(route('invoices.service.review'))->assertForbidden();
    });

    it('marks a due invoice as paid, stamps paid_at and writes the commission ledger', function () {
        $invoice = makeDueInvoice();

        // No commission is earned while the invoice is due.
        expect(CommissionLedger::count())->toBe(0);

        $this->from(route('invoices.service.review'))
            ->patch(route('invoices.service.pay', $invoice))
            ->assertRedirect(route('invoices.service.review'))
            ->assertSessionHas('success');

        $invoice->refresh();
        expect($invoice->status->value)->toBe('paid')
            ->and($invoice->paid_at)->not->toBeNull();

        // Approval realises the employee's commission: one immutable ledger row.
        $entry = CommissionLedger::firstOrFail();
        expect(CommissionLedger::count())->toBe(1)
            ->and($entry->user_id)->toBe($this->employee->id)
            ->and((float) $entry->amount)->toBe(10.00)
            ->and($entry->invoice_line_id)->toBe($invoice->lines()->value('id'));
    });

    it('credits loyalty points when settling for an eligible customer', function () {
        LoyaltyConfig::query()->create(['branch_id' => $this->branch->id, 'earning_rate' => 1, 'is_active' => true]);

        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_type' => CustomerTypeEnum::Individual,
            'points_balance' => 0,
        ]);

        $invoice = makeDueInvoice(['customer_id' => $customer->id]);

        $this->patch(route('invoices.service.pay', $invoice));

        // floor(115 * 1) = 115 points earned on payment.
        expect($customer->refresh()->points_balance)->toBe(115)
            ->and(LoyaltyTransaction::where('type', LoyaltyTransactionTypeEnum::Earn)->count())->toBe(1);
    });

    it('rejects settling an invoice that is not due', function () {
        $invoice = makeDueInvoice(['status' => 'paid', 'paid_at' => now()]);

        $this->patch(route('invoices.service.pay', $invoice))
            ->assertSessionHasErrors('status');
    });

    it('cancels a due invoice without touching the commission ledger', function () {
        $invoice = makeDueInvoice();

        $this->patch(route('invoices.service.cancel', $invoice), ['reason' => 'طلب العميل الإلغاء'])
            ->assertRedirect(route('invoices.service.review'))
            ->assertSessionHas('success');

        $invoice->refresh();
        expect($invoice->status->value)->toBe('cancelled')
            ->and($invoice->cancellation_reason)->toBe('طلب العميل الإلغاء');

        // A due invoice never accrued commission (the ledger is written only on
        // approval), so there is nothing to reverse — the ledger stays empty.
        $lineIds = $invoice->lines()->pluck('id');
        expect(CommissionLedger::whereIn('invoice_line_id', $lineIds)->count())->toBe(0);
    });

    it('restores redeemed points when cancelling', function () {
        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_type' => CustomerTypeEnum::Individual,
            'points_balance' => 200,
        ]);

        $invoice = makeDueInvoice(['customer_id' => $customer->id, 'points_redeemed' => 500, 'points_discount' => 5]);

        $this->patch(route('invoices.service.cancel', $invoice), ['reason' => 'إلغاء بطلب الإدارة']);

        expect($customer->refresh()->points_balance)->toBe(700)
            ->and(LoyaltyTransaction::where('type', LoyaltyTransactionTypeEnum::ManualAdjust)->where('points', 500)->count())->toBe(1);
    });

    it('requires a reason to cancel', function () {
        $invoice = makeDueInvoice();

        $this->patch(route('invoices.service.cancel', $invoice), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        expect($invoice->refresh()->status->value)->toBe('due');
    });

    it('notifies the employee, branch admin and super admin when accepting', function () {
        Notification::fake();

        $branchAdmin = User::factory()->create();
        $branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $branchAdmin->id]);

        $superAdmin = User::factory()->create();
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $invoice = makeDueInvoice();

        $this->patch(route('invoices.service.pay', $invoice));

        Notification::assertSentTo([$this->employee, $branchAdmin, $superAdmin], ServiceInvoiceReviewedNotification::class);
        // The accountant who made the decision is not notified.
        Notification::assertNotSentTo([$this->accountant], ServiceInvoiceReviewedNotification::class);
    });

    it('notifies the employee on rejection with the cancellation reason', function () {
        Notification::fake();

        $invoice = makeDueInvoice();

        $this->patch(route('invoices.service.cancel', $invoice), ['reason' => 'بيانات ناقصة']);

        Notification::assertSentTo(
            [$this->employee],
            ServiceInvoiceReviewedNotification::class,
            function (ServiceInvoiceReviewedNotification $n) {
                $data = $n->toArray($this->employee);

                return $data['type'] === 'invoice_rejected' && str_contains($data['body'], 'بيانات ناقصة');
            },
        );
    });

    it('forbids settling an invoice from another branch', function () {
        $otherBranch = Branch::factory()->create();
        $invoice = makeDueInvoice(['branch_id' => $otherBranch->id]);

        $this->patch(route('invoices.service.pay', $invoice))->assertForbidden();

        expect($invoice->refresh()->status->value)->toBe('due');
    });

    it('lets an accountant edit the linked customer name and phone from the review queue', function () {
        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'full_name' => 'اسم قديم',
            'phone' => '0500000001',
        ]);

        $invoice = makeDueInvoice(['customer_id' => $customer->id]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => 'اسم جديد',
            'phone' => '0500000002',
        ])
            ->assertRedirect(route('invoices.service.review'))
            ->assertSessionHas('success');

        expect($customer->refresh())
            ->full_name->toBe('اسم جديد')
            ->phone->toBe('0500000002');
    });

    it('updates the customer tax number from the review queue', function () {
        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'full_name' => 'عميل',
            'phone' => '0500000010',
            'tax_number' => null,
        ]);

        $invoice = makeDueInvoice(['customer_id' => $customer->id]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => 'عميل',
            'phone' => '0500000010',
            'tax_number' => '300000000000003',
        ])->assertSessionHasNoErrors();

        expect($customer->refresh()->tax_number)->toBe('300000000000003');
    });

    it('registers a walk-in customer with a tax number', function () {
        $invoice = makeDueInvoice(['customer_id' => null]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => 'عميل جديد',
            'phone' => '0500000011',
            'tax_number' => '300000000000004',
        ])->assertSessionHasNoErrors();

        $customer = Customer::where('phone', '0500000011')->where('branch_id', $this->branch->id)->first();

        expect($customer)->not->toBeNull()
            ->and($customer->tax_number)->toBe('300000000000004');
    });

    it('rejects a tax number that is not 15 digits', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $invoice = makeDueInvoice(['customer_id' => $customer->id]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => $customer->full_name,
            'phone' => $customer->phone,
            'tax_number' => '123',
        ])->assertSessionHasErrors('tax_number');
    });

    it('validates name and phone when editing the customer', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $invoice = makeDueInvoice(['customer_id' => $customer->id]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => '',
            'phone' => '',
        ])->assertSessionHasErrors(['full_name', 'phone']);
    });

    it('rejects a phone already used by another customer in the branch', function () {
        Customer::factory()->create(['branch_id' => $this->branch->id, 'phone' => '0512345678']);

        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'phone' => '0599999999']);
        $invoice = makeDueInvoice(['customer_id' => $customer->id]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => $customer->full_name,
            'phone' => '0512345678',
        ])->assertSessionHasErrors('phone');

        expect($customer->refresh()->phone)->toBe('0599999999');
    });

    it('registers and links a new customer for a walk-in invoice with none', function () {
        $invoice = makeDueInvoice(['customer_id' => null]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => 'عميل جديد',
            'phone' => '0500000003',
        ])
            ->assertRedirect(route('invoices.service.review'))
            ->assertSessionHas('success');

        $customer = Customer::where('phone', '0500000003')->where('branch_id', $this->branch->id)->first();

        expect($customer)->not->toBeNull()
            ->and($customer->full_name)->toBe('عميل جديد')
            ->and($customer->customer_type)->toBe(CustomerTypeEnum::Individual)
            ->and($invoice->refresh()->customer_id)->toBe($customer->id);
    });

    it('links an existing customer by phone instead of duplicating when adding to a walk-in', function () {
        $existing = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'full_name' => 'عميل مسجَّل',
            'phone' => '0500000006',
        ]);

        $invoice = makeDueInvoice(['customer_id' => null]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => 'اسم مختلف',
            'phone' => '0500000006',
        ])->assertRedirect(route('invoices.service.review'));

        expect(Customer::where('phone', '0500000006')->where('branch_id', $this->branch->id)->count())->toBe(1)
            ->and($invoice->refresh()->customer_id)->toBe($existing->id);
    });

    it('lets an accountant change the payment method from the review queue', function () {
        $card = PaymentMethod::factory()->create(['name' => 'بطاقة بنكية']);
        $mada = PaymentMethod::factory()->create(['name' => 'مدى']);

        $invoice = makeDueInvoice(['payment_method_id' => $card->id]);

        $this->patch(route('invoices.service.update-payment-method', $invoice), [
            'payment_method_id' => $mada->id,
        ])
            ->assertRedirect(route('invoices.service.review'))
            ->assertSessionHas('success');

        expect($invoice->refresh()->payment_method_id)->toBe($mada->id);
    });

    it('rejects a payment method that is not enabled for the branch', function () {
        $card = PaymentMethod::factory()->create(['name' => 'بطاقة بنكية']);
        $disabled = PaymentMethod::factory()->create(['name' => 'قديمة', 'is_active' => false]);

        $invoice = makeDueInvoice(['payment_method_id' => $card->id]);

        $this->patch(route('invoices.service.update-payment-method', $invoice), [
            'payment_method_id' => $disabled->id,
        ])->assertSessionHasErrors('payment_method_id');

        expect($invoice->refresh()->payment_method_id)->toBe($card->id);
    });

    it('forbids an accountant from changing the payment method on another branch invoice', function () {
        $otherBranch = Branch::factory()->create();
        $method = PaymentMethod::factory()->create();
        $invoice = makeDueInvoice(['branch_id' => $otherBranch->id]);

        $this->patch(route('invoices.service.update-payment-method', $invoice), [
            'payment_method_id' => $method->id,
        ])->assertForbidden();
    });

    it('requires name and phone when adding a customer to a walk-in invoice', function () {
        $invoice = makeDueInvoice(['customer_id' => null]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => '',
            'phone' => '',
        ])->assertSessionHasErrors(['full_name', 'phone']);

        expect($invoice->refresh()->customer_id)->toBeNull();
    });

    it('forbids an employee from editing the customer of an invoice they did not raise', function () {
        // The employee who raised it may correct its customer (see
        // ServiceInvoiceEditDeleteTest); a colleague still may not.
        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($other);

        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'full_name' => 'عميل']);
        $invoice = makeDueInvoice(['customer_id' => $customer->id]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => 'محاولة',
            'phone' => '0500000004',
        ])->assertForbidden();

        expect($customer->refresh()->full_name)->toBe('عميل');
    });

    it('forbids editing a customer on an invoice from another branch', function () {
        $otherBranch = Branch::factory()->create();
        $customer = Customer::factory()->create(['branch_id' => $otherBranch->id, 'full_name' => 'عميل آخر']);
        $invoice = makeDueInvoice(['branch_id' => $otherBranch->id, 'customer_id' => $customer->id]);

        $this->patch(route('invoices.service.update-customer', $invoice), [
            'full_name' => 'تعديل',
            'phone' => '0500000005',
        ])->assertForbidden();

        expect($customer->refresh()->full_name)->toBe('عميل آخر');
    });
});
