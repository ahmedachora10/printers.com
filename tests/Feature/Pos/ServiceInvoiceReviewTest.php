<?php

use App\Enums\CommissionSourceTypeEnum;
use App\Enums\CustomerTypeEnum;
use App\Enums\LoyaltyTransactionTypeEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use App\Notifications\ServiceInvoiceReviewedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * Build a due service invoice with one line and its commission ledger row,
 * mirroring what the employee POS produces.
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

    /** @var ServiceInvoiceLine $line */
    $line = $invoice->lines()->create([
        'service_name' => 'خدمة اختبار',
        'qty' => 1,
        'unit_price' => 100,
        'discount_pct' => 0,
        'subtotal' => 100,
        'commission_pct' => 10,
        'commission_amount' => $commission,
    ]);

    if ($commission > 0) {
        CommissionLedger::create([
            'user_id' => test()->employee->id,
            'branch_id' => $invoice->branch_id,
            'invoice_line_id' => $line->id,
            'invoice_line_type' => ServiceInvoiceLine::class,
            'amount' => $commission,
            'is_tahazir' => false,
            'source_type' => CommissionSourceTypeEnum::STANDARD,
            'earned_at' => now(),
        ]);
    }

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

    it('marks a due invoice as paid and stamps paid_at', function () {
        $invoice = makeDueInvoice();

        $this->patch(route('invoices.service.pay', $invoice))
            ->assertRedirect(route('invoices.service.review'))
            ->assertSessionHas('success');

        $invoice->refresh();
        expect($invoice->status->value)->toBe('paid')
            ->and($invoice->paid_at)->not->toBeNull();
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

    it('cancels a due invoice and reverses unpaid commission to zero', function () {
        $invoice = makeDueInvoice();

        $this->patch(route('invoices.service.cancel', $invoice), ['reason' => 'طلب العميل الإلغاء'])
            ->assertRedirect(route('invoices.service.review'))
            ->assertSessionHas('success');

        $invoice->refresh();
        expect($invoice->status->value)->toBe('cancelled')
            ->and($invoice->cancellation_reason)->toBe('طلب العميل الإلغاء');

        // Original +10 plus a -10 reversal = net 0, with the ledger never mutated.
        $lineIds = $invoice->lines()->pluck('id');
        expect(CommissionLedger::whereIn('invoice_line_id', $lineIds)->count())->toBe(2)
            ->and((float) CommissionLedger::whereIn('invoice_line_id', $lineIds)->sum('amount'))->toBe(0.0);
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
});
