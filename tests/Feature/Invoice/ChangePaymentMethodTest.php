<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\InvoicePayment;
use App\Models\PaymentMethod;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/** تاسك 99 — فاتورة خدمات بحالةٍ معيّنة، للاختبارات أدناه وحدها. */
function methodChangeServiceInvoice(Branch $branch, User $owner, array $overrides = []): ServiceInvoice
{
    return ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-PM-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branch->id,
        'user_id' => $owner->id,
        'subtotal' => 200,
        'vat_pct' => 15,
        'vat_amount' => 30,
        'total_amount' => 230,
        'employee_commission' => 0,
        'status' => 'paid',
        'paid_at' => now(),
    ], $overrides));
}

function methodChangePayment(ServiceInvoice $invoice, ?PaymentMethod $method, float $amount, User $by): InvoicePayment
{
    return InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'invoice_type' => ServiceInvoice::class,
        'branch_id' => $invoice->branch_id,
        'payment_method_id' => $method?->id,
        'amount' => $amount,
        'paid_at' => now()->subHour(),
        'recorded_by' => $by->id,
    ]);
}

describe('Change payment method (task 99)', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->cash = PaymentMethod::factory()->cash()->create(['name' => 'نقد']);
        $this->card = PaymentMethod::factory()->create(['name' => 'شبكة']);
    });

    it('lets the accountant correct the method of an approved invoice, and logs it', function () {
        $invoice = methodChangeServiceInvoice($this->branch, $this->employee, ['payment_method_id' => $this->card->id]);

        $this->actingAs($this->accountant)
            ->from(route('invoices.show', ['type' => 'service', 'id' => $invoice->id]))
            ->patch(route('invoices.update-payment-method', ['type' => 'service', 'id' => $invoice->id]), [
                'payment_method_id' => $this->cash->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('invoices.show', ['type' => 'service', 'id' => $invoice->id]));

        expect($invoice->refresh()->payment_method_id)->toBe($this->cash->id);

        $log = Activity::query()->forSubject($invoice)->where('description', 'payment method changed')->sole();
        expect($log->properties['old'])->toBe('شبكة')
            ->and($log->properties['new'])->toBe('نقد')
            ->and($log->causer_id)->toBe($this->accountant->id);

        // والمبلغ ينتقل في تقرير المبيعات إلى النقد.
        $this->actingAs($this->accountant)
            ->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page->where('totals.cash', 230));
    });

    it('shows the edit button and the change history on an approved invoice', function () {
        $invoice = methodChangeServiceInvoice($this->branch, $this->employee, ['payment_method_id' => $this->card->id]);

        $this->actingAs($this->accountant)
            ->patch(route('invoices.update-payment-method', ['type' => 'service', 'id' => $invoice->id]), [
                'payment_method_id' => $this->cash->id,
            ]);

        $this->actingAs($this->accountant)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $invoice->id]))
            ->assertInertia(fn ($page) => $page
                ->where('invoice.canEditPaymentMethod', true)
                ->where('invoice.canEditPaymentRows', false)
                ->has('paymentMethodHistory', 1)
                ->where('paymentMethodHistory.0.old', 'شبكة')
                ->where('paymentMethodHistory.0.new', 'نقد'));
    });

    it('keeps the employee out', function () {
        $invoice = methodChangeServiceInvoice($this->branch, $this->employee, ['payment_method_id' => $this->card->id]);

        $this->actingAs($this->employee)
            ->patch(route('invoices.update-payment-method', ['type' => 'service', 'id' => $invoice->id]), [
                'payment_method_id' => $this->cash->id,
            ])
            ->assertForbidden();

        expect($invoice->refresh()->payment_method_id)->toBe($this->card->id);
    });

    it('refuses a returned invoice', function () {
        $invoice = methodChangeServiceInvoice($this->branch, $this->employee, [
            'payment_method_id' => $this->card->id,
            'status' => 'returned',
        ]);

        $this->actingAs($this->accountant)
            ->patch(route('invoices.update-payment-method', ['type' => 'service', 'id' => $invoice->id]), [
                'payment_method_id' => $this->cash->id,
            ])
            ->assertForbidden();
    });

    it('requires a receipt when switching to a method that needs one', function () {
        $transfer = PaymentMethod::factory()->requiresAttachment()->create(['name' => 'تحويل بنكي']);
        $invoice = methodChangeServiceInvoice($this->branch, $this->employee, ['payment_method_id' => $this->card->id]);

        $this->actingAs($this->accountant)
            ->patch(route('invoices.update-payment-method', ['type' => 'service', 'id' => $invoice->id]), [
                'payment_method_id' => $transfer->id,
            ])
            ->assertSessionHasErrors('receipt');

        expect($invoice->refresh()->payment_method_id)->toBe($this->card->id);
    });

    it('lets the accountant correct a product invoice too', function () {
        $invoice = ProductInvoice::create([
            'invoice_number' => 'INV-PM-1',
            'branch_id' => $this->branch->id,
            'user_id' => $this->accountant->id,
            'payment_method_id' => $this->card->id,
            'subtotal' => 100,
            'vat_pct' => 15,
            'vat_amount' => 15,
            'total_amount' => 115,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($this->accountant)
            ->patch(route('invoices.update-payment-method', ['type' => 'product', 'id' => $invoice->id]), [
                'payment_method_id' => $this->cash->id,
            ])
            ->assertSessionHasNoErrors();

        expect($invoice->refresh()->payment_method_id)->toBe($this->cash->id);
    });

    // ── فاتورة سُدّدت بدفعات (لقطة الصفحة 4) ──────────────────────────

    it('changes the method of one payment row, never its amount or date', function () {
        $invoice = methodChangeServiceInvoice($this->branch, $this->employee, ['payment_method_id' => null]);
        $payment = methodChangePayment($invoice, $this->card, 230, $this->accountant);
        $paidAt = $payment->paid_at->toDateTimeString();

        $this->actingAs($this->accountant)
            ->patch(route('invoice-payments.update-payment-method', $payment), [
                'payment_method_id' => $this->cash->id,
            ])
            ->assertSessionHasNoErrors();

        $payment->refresh();
        expect($payment->payment_method_id)->toBe($this->cash->id)
            ->and((float) $payment->amount)->toBe(230.0)
            ->and($payment->paid_at->toDateTimeString())->toBe($paidAt)
            ->and(InvoicePayment::count())->toBe(1);

        expect(Activity::query()->forSubject($invoice)->where('description', 'payment method changed')->sole()->properties['payment_id'])
            ->toBe($payment->id);
    });

    it('sends an invoice paid by instalments to its payment rows', function () {
        $invoice = methodChangeServiceInvoice($this->branch, $this->employee, ['payment_method_id' => null]);
        methodChangePayment($invoice, $this->card, 230, $this->accountant);

        $this->actingAs($this->accountant)
            ->patch(route('invoices.update-payment-method', ['type' => 'service', 'id' => $invoice->id]), [
                'payment_method_id' => $this->cash->id,
            ])
            ->assertSessionHasErrors('payment_method_id');

        $this->actingAs($this->accountant)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $invoice->id]))
            ->assertInertia(fn ($page) => $page
                ->where('invoice.canEditPaymentMethod', false)
                ->where('invoice.canEditPaymentRows', true));
    });

    it('names the method of an invoice paid by a deposit instead of a dash', function () {
        $invoice = methodChangeServiceInvoice($this->branch, $this->employee, ['payment_method_id' => null]);
        methodChangePayment($invoice, $this->card, 50, $this->accountant);
        methodChangePayment($invoice, $this->cash, 180, $this->accountant);

        $this->actingAs($this->accountant)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $invoice->id]))
            ->assertInertia(fn ($page) => $page->where('invoice.paymentMethod', 'شبكة + نقد'));

        $this->actingAs($this->accountant)
            ->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->where('items.data.0.paymentMethodName', 'شبكة + نقد'));
    });

    it('finds an invoice paid by a deposit under its payment method filter', function () {
        $deposit = methodChangeServiceInvoice($this->branch, $this->employee, ['payment_method_id' => null]);
        methodChangePayment($deposit, $this->card, 230, $this->accountant);
        // فاتورةٌ طريقتها على الفاتورة «شبكة» لكن دفعاتها نقداً — تُقرأ بدفعاتها.
        $cashRows = methodChangeServiceInvoice($this->branch, $this->employee, ['payment_method_id' => $this->card->id]);
        methodChangePayment($cashRows, $this->cash, 230, $this->accountant);
        $direct = methodChangeServiceInvoice($this->branch, $this->employee, ['payment_method_id' => $this->card->id]);

        $this->actingAs($this->accountant)
            ->get(route('invoices.index', ['payment_method_id' => $this->card->id]))
            ->assertInertia(function ($page) use ($deposit, $direct) {
                $ids = collect($page->toArray()['props']['items']['data'])->pluck('id')->sort()->values()->all();
                expect($ids)->toBe(collect([$deposit->id, $direct->id])->sort()->values()->all());

                return $page;
            });
    });
});
