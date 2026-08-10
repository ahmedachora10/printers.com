<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\InvoicePayment;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Notifications\ServiceInvoiceReviewedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * نظام الدفعات: عربون + دفعات لاحقة على فاتورة خدمة. الفاتورة تُصبح «مدفوعة
 * جزئياً» ما دام المحصَّل أقل من الإجمالي، وتُغلق «مدفوعة» عند اكتماله — عندها
 * فقط تُكتب العمولة وتُحتسب النقاط، ومرة واحدة لا أكثر.
 */
function paymentTestInvoice(array $overrides = [], float $commission = 10): ServiceInvoice
{
    $invoice = ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-PAY-'.fake()->unique()->numerify('#####'),
        'branch_id' => test()->branch->id,
        'user_id' => test()->employee->id,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 15,
        'total_amount' => 115,
        'employee_commission' => $commission,
        'status' => InvoiceStatusEnum::DUE->value,
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

/**
 * مطابقة مبلغ في حمولة Inertia: القيمة 40.0 تُرسَّل JSON كـ 40، فلا تصلح
 * المقارنة الصارمة — تُقارن بعد التقريب لمنزلتين.
 */
function money(float $expected): Closure
{
    return fn ($actual) => round((float) $actual, 2) === round($expected, 2);
}

/**
 * POST دفعة على فاتورة خدمة. طريقة الدفع إلزامية على الخادم، فتُملأ افتراضياً
 * بالطريقة النقدية المهيّأة في beforeEach ما لم يمرّر الاختبار غيرها صراحةً.
 */
function postPayment(ServiceInvoice $invoice, array $payload): TestResponse
{
    return test()->post(
        route('invoices.payments.store', ['type' => 'service', 'id' => $invoice->id]),
        array_merge(['payment_method_id' => test()->cash->id], $payload),
    );
}

describe('Invoice payments', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        // طريقة الدفع إلزامية على كل دفعة، فالفرع يحتاج طريقة مفعّلة واحدة على
        // الأقل. «تحويل بنكي» تشترط إيصالاً وتُستعمل في اختبارات المرفق.
        $this->cash = PaymentMethod::factory()->create(['is_active' => true, 'requires_attachment' => false]);
        $this->transfer = PaymentMethod::factory()->create(['is_active' => true, 'requires_attachment' => true]);

        $this->actingAs($this->accountant);
    });

    // ── دفعتان تكملان الفاتورة ────────────────────────────────────

    it('settles the invoice on the second payment and writes the commission ledger once', function () {
        Notification::fake();
        $invoice = paymentTestInvoice();

        // العربون: الفاتورة تصير مدفوعة جزئياً، ولا عمولة بعد.
        postPayment($invoice, ['amount' => 50])->assertRedirect();

        $invoice->refresh();
        expect($invoice->status)->toBe(InvoiceStatusEnum::PARTIALLY_PAID)
            ->and($invoice->paid_at)->toBeNull()
            ->and($invoice->paidAmount())->toBe(50.0)
            ->and($invoice->remainingAmount())->toBe(65.0)
            ->and(CommissionLedger::count())->toBe(0);

        // دفعة المتبقي: تُغلق الفاتورة وتُكتب العمولة.
        $this->travel(1)->days();
        postPayment($invoice, ['amount' => 65])->assertRedirect();

        $invoice->refresh();
        expect($invoice->status)->toBe(InvoiceStatusEnum::PAID)
            ->and($invoice->paidAmount())->toBe(115.0)
            ->and($invoice->remainingAmount())->toBe(0.0)
            ->and($invoice->payments()->count())->toBe(2);

        // سطر واحد فقط في الـ ledger — عن السطر الوحيد في الفاتورة، لا مرتين.
        expect(CommissionLedger::count())->toBe(1)
            ->and((float) CommissionLedger::first()->amount)->toBe(10.0);

        // تاريخ السداد لحظة آخر دفعة، لا لحظة إنشاء الفاتورة.
        $lastPayment = $invoice->payments()->latest('paid_at')->first();
        expect($invoice->paid_at->toIso8601String())->toBe($lastPayment->paid_at->toIso8601String());

        Notification::assertSentTo($this->employee, ServiceInvoiceReviewedNotification::class);
    });

    it('refuses another payment once the invoice is settled, so nothing is written twice', function () {
        $invoice = paymentTestInvoice();

        postPayment($invoice, ['amount' => 115])->assertRedirect();
        expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::PAID)
            ->and(CommissionLedger::count())->toBe(1);

        // الحارس الأول هو السياسة: فاتورة مسدَّدة لا تقبل تحصيلاً أصلاً.
        postPayment($invoice, ['amount' => 10])->assertForbidden();

        expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::PAID)
            ->and($invoice->payments()->count())->toBe(1)
            ->and(CommissionLedger::count())->toBe(1);
    });

    // ── دفعة زائدة ────────────────────────────────────────────────

    it('rejects a payment that exceeds the remaining amount', function () {
        $invoice = paymentTestInvoice();
        postPayment($invoice, ['amount' => 100])->assertRedirect();

        postPayment($invoice, ['amount' => 20])
            ->assertSessionHasErrors(['amount' => 'المبلغ يتجاوز المتبقي على الفاتورة. المتبقي: 15.00 ر.س.']);

        $invoice->refresh();
        expect($invoice->status)->toBe(InvoiceStatusEnum::PARTIALLY_PAID)
            ->and($invoice->payments()->count())->toBe(1)
            ->and($invoice->paidAmount())->toBe(100.0);
    });

    it('rejects a payment of zero or less', function () {
        $invoice = paymentTestInvoice();

        postPayment($invoice, ['amount' => 0])->assertSessionHasErrors('amount');
        postPayment($invoice, ['amount' => -5])->assertSessionHasErrors('amount');

        expect(InvoicePayment::count())->toBe(0);
    });

    it('forbids an employee from recording a payment', function () {
        $invoice = paymentTestInvoice();

        $this->actingAs($this->employee);
        postPayment($invoice, ['amount' => 50])->assertForbidden();

        expect(InvoicePayment::count())->toBe(0);
    });

    it('drops the invoice out of the quotation review queue once a deposit is taken', function () {
        $invoice = paymentTestInvoice();
        $untouched = paymentTestInvoice();

        $this->get(route('invoices.service.review'))
            ->assertInertia(fn ($page) => $page->has('invoices', 2));

        postPayment($invoice, ['amount' => 40])->assertRedirect();

        // الفاتورة التي قُبض عليها عربون لم تعد عرض سعر، فتغادر الطابور ويُستكمل
        // سدادها من صفحتها.
        $this->get(route('invoices.service.review'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/review')
                ->has('invoices', 1)
                ->where('invoices.0.id', $untouched->id));
    });

    it('refuses to approve a partially paid invoice outright', function () {
        $invoice = paymentTestInvoice();
        postPayment($invoice, ['amount' => 50])->assertRedirect();

        $this->from(route('invoices.service.review'))
            ->patch(route('invoices.service.pay', $invoice))
            ->assertSessionHasErrors('status');

        expect($invoice->refresh()->status)->toBe(InvoiceStatusEnum::PARTIALLY_PAID)
            ->and(CommissionLedger::count())->toBe(0);
    });

    it('records the payment method of each instalment', function () {
        // جدول payment_methods عام لا يحمل branch_id — الفروع تُفعّل منه ما تشاء.
        $cash = PaymentMethod::factory()->create(['is_active' => true]);
        $invoice = paymentTestInvoice();

        postPayment($invoice, ['amount' => 40, 'payment_method_id' => $cash->id, 'notes' => 'عربون نقداً'])
            ->assertRedirect();

        $payment = $invoice->payments()->first();
        expect($payment->payment_method_id)->toBe($cash->id)
            ->and($payment->notes)->toBe('عربون نقداً')
            ->and($payment->recorded_by)->toBe($this->accountant->id)
            ->and($payment->branch_id)->toBe($this->branch->id);
    });

    // ── طريقة الدفع إلزامية وإيصال التحويل (تاسك 38) ──────────────

    it('rejects a payment with no payment method', function () {
        $invoice = paymentTestInvoice();

        // دفعة بلا طريقة تسقط من تفصيل طرق الدفع في التقارير — ترفضها القاعدة.
        $this->post(
            route('invoices.payments.store', ['type' => 'service', 'id' => $invoice->id]),
            ['amount' => 40],
        )->assertSessionHasErrors('payment_method_id');

        expect($invoice->payments()->count())->toBe(0);
    });

    it('rejects a payment method that the branch has not enabled', function () {
        $disabled = PaymentMethod::factory()->create(['is_active' => false]);
        $invoice = paymentTestInvoice();

        postPayment($invoice, ['amount' => 40, 'payment_method_id' => $disabled->id])
            ->assertSessionHasErrors('payment_method_id');

        expect($invoice->payments()->count())->toBe(0);
    });

    it('rejects a bank transfer with no receipt attached', function () {
        $invoice = paymentTestInvoice();

        postPayment($invoice, ['amount' => 40, 'payment_method_id' => $this->transfer->id])
            ->assertSessionHasErrors('receipt');

        expect($invoice->payments()->count())->toBe(0);
    });

    it('stores the receipt of a bank transfer and exposes it on the payment', function () {
        Storage::fake('local');
        $invoice = paymentTestInvoice();

        postPayment($invoice, [
            'amount' => 40,
            'payment_method_id' => $this->transfer->id,
            'receipt' => UploadedFile::fake()->image('transfer.jpg'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $payment = $invoice->payments()->firstOrFail();

        expect($payment->receipt())->not->toBeNull()
            ->and($payment->receiptUrl())->toBe(route('invoices.payments.receipt', ['payment' => $payment->id]));

        // الإيصال على القرص الخاص ولا يُقدَّم إلا من المسار المحمي بالصلاحية.
        $this->get($payment->receiptUrl())->assertOk();
    });

    it('needs no receipt for a method that does not require one', function () {
        $invoice = paymentTestInvoice();

        postPayment($invoice, ['amount' => 40])->assertRedirect()->assertSessionHasNoErrors();

        expect($invoice->payments()->firstOrFail()->receipt())->toBeNull();
    });

    it('hides a payment receipt from staff of another branch', function () {
        Storage::fake('local');
        $invoice = paymentTestInvoice();

        postPayment($invoice, [
            'amount' => 40,
            'payment_method_id' => $this->transfer->id,
            'receipt' => UploadedFile::fake()->image('transfer.jpg'),
        ])->assertRedirect();

        $payment = $invoice->payments()->firstOrFail();

        $outsider = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $outsider->addRole(Roles::ACCOUNTANT->value);

        $this->actingAs($outsider)->get($payment->receiptUrl())->assertForbidden();
    });

    // ── الظهور في القائمة والتقارير ────────────────────────────────

    it('shows a deposited invoice as partially paid in the invoice list, with its remaining amount', function () {
        $invoice = paymentTestInvoice();
        postPayment($invoice, ['amount' => 40])->assertRedirect();

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/index')
                ->has('items.data', 1)
                ->where('items.data.0.status', 'partially_paid')
                ->where('items.data.0.statusLabel', 'مدفوعة جزئياً')
                ->where('items.data.0.paidAmount', money(40))
                ->where('items.data.0.remainingAmount', money(75)));
    });

    it('counts only the collected amount as realized revenue in the sales report', function () {
        $invoice = paymentTestInvoice();
        postPayment($invoice, ['amount' => 40])->assertRedirect();

        $this->get(route('reports.sales'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/sales/index')
                // 40 محصَّلة من 115 — لا الإجمالي كاملاً.
                ->where('totals.total', money(40))
                ->where('totals.invoiceCount', 1)
                // الأرقام الفرعية تُوزَّع بحصة الدفعة: 100 × (40/115).
                ->where('totals.subtotal', money(34.78))
                ->where('totals.vat', money(5.22)));
    });

    it('reports the collected amount on the day the money came in', function () {
        $invoice = paymentTestInvoice();
        postPayment($invoice, ['amount' => 40])->assertRedirect();

        $this->get(route('reports.daily'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/daily/index')
                ->where('totals.collected', money(40))
                // الإجمالي يبقى على الاستحقاق: صافي الفاتورة قبل الضريبة.
                ->where('totals.total', money(100)));
    });

    // ── ورقة الطباعة ──────────────────────────────────────────────

    it('prints a deposited invoice as a tax invoice carrying the branch tax number and the ZATCA QR', function () {
        $invoice = paymentTestInvoice();

        // قبل أي دفعة: عرض سعر مجرَّد من مقوّمات الفاتورة الضريبية.
        $this->get(route('invoices.print', ['type' => 'service', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.status', 'due')
                ->where('invoice.branch.taxNumber', null)
                ->where('zatcaQr', null));

        postPayment($invoice, ['amount' => 40])->assertRedirect();

        // العربون سداد: الورقة صارت فاتورة ضريبية كاملة المقوّمات.
        $this->get(route('invoices.print', ['type' => 'service', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/print')
                ->where('invoice.status', 'partially_paid')
                ->where('invoice.branch.taxNumber', $this->branch->tax_number)
                ->whereNot('zatcaQr', null)
                ->where('invoice.paidAmount', money(40))
                ->where('invoice.paymentRemaining', money(75)));
    });

    it('encodes the whole invoice total in the ZATCA QR, not the collected deposit', function () {
        $invoice = paymentTestInvoice();
        postPayment($invoice, ['amount' => 40])->assertRedirect();

        $response = $this->get(route('invoices.print', ['type' => 'service', 'id' => $invoice->id]));
        $tlv = base64_decode($response->viewData('page')['props']['zatcaQr']);

        // الفاتورة مستحقة بكامل قيمتها والضريبة على كاملها — لا على المقبوض.
        expect($tlv)->toContain('115.00')
            ->and($tlv)->toContain('15.00')
            ->and($tlv)->not->toContain('40.00');
    });

    it('prints the deposit and the remaining amount on the POS receipt', function () {
        $invoice = paymentTestInvoice();
        postPayment($invoice, ['amount' => 40])->assertRedirect();

        // ورقة نقطة البيع محجوزة لمن يصدر الفواتير، لا للمحاسب الذي سجّل الدفعة.
        $this->actingAs($this->employee)
            ->get(route('pos.service.print', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos/service/print')
                ->where('invoice.status', 'partially_paid')
                ->where('invoice.hasPayments', true)
                ->where('invoice.paidAmount', money(40))
                ->where('invoice.paymentRemaining', money(75))
                ->where('branch.taxNumber', $this->branch->tax_number));
    });

    it('shows the payments and the remaining amount on the invoice page', function () {
        $invoice = paymentTestInvoice();
        postPayment($invoice, ['amount' => 40])->assertRedirect();

        $this->get(route('invoices.show', ['type' => 'service', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/show')
                ->where('invoice.paidAmount', money(40))
                ->where('invoice.paymentRemaining', money(75))
                ->where('invoice.canRecordPayment', true)
                ->has('invoice.payments', 1)
                ->where('invoice.payments.0.amount', money(40)));
    });
});
