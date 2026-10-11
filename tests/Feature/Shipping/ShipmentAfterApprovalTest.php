<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\DeliveryProvider;
use App\Models\DeliveryZone;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Notifications\ShipmentRequestNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * توصيلٌ يطلبه العميل بعد اعتماد فاتورته: يطلبه أيّ مستخدم، ويعتمده المحاسب فيُضاف
 * رسمه متبقّياً على الفاتورة المدفوعة، ويُحصَّل من «تسجيل دفعة» بلا إعادة اعتماد.
 */
describe('Shipment requested after approval', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->cash = PaymentMethod::factory()->create(['is_active' => true, 'requires_attachment' => false]);
        $this->driver = DeliveryProvider::factory()->create(['branch_id' => $this->branch->id]);
        $this->zone = DeliveryZone::factory()->create(['branch_id' => $this->branch->id, 'price' => 23]);

        // فاتورة 115 بعمولة 10، معتمدةٌ فعلاً: العمولة في الدفتر.
        $this->invoice = ServiceInvoice::create([
            'invoice_number' => 'SINV-SHIP-00001',
            'branch_id' => $this->branch->id,
            'user_id' => $this->employee->id,
            'payment_method_id' => $this->cash->id,
            'subtotal' => 115,
            'vat_pct' => 15,
            'vat_amount' => 15,
            'total_amount' => 115,
            'employee_commission' => 10,
            'status' => InvoiceStatusEnum::DUE,
        ]);
        $this->invoice->lines()->create([
            'service_name' => 'لوحة', 'qty' => 1, 'unit_price' => 115, 'discount_pct' => 0,
            'subtotal' => 115, 'commission_pct' => 10, 'commission_amount' => 10, 'is_tahazir' => false,
        ]);
        $this->actingAs($this->accountant)->patch(route('invoices.service.pay', $this->invoice))->assertSessionHasNoErrors();

        $this->request = fn (User $user, array $shipment = []) => $this->actingAs($user)->post(
            route('invoices.service.shipment-requests.store', $this->invoice),
            ['shipments' => [array_merge(['provider_id' => $this->driver->id, 'zone_id' => $this->zone->id, 'address' => 'حي النرجس'], $shipment)]],
        );
    });

    it('keeps a request out of the invoice until the accountant approves it', function () {
        Notification::fake();

        // الموظف لا يكتب الرسم: يُؤخذ سعر الشريحة.
        ($this->request)($this->employee, ['fee' => 1])->assertSessionHasNoErrors();

        $invoice = $this->invoice->fresh();
        $pending = $invoice->pendingShipments()->sole();

        expect((float) $pending->fee)->toBe(23.0)
            ->and($pending->requested_by)->toBe($this->employee->id)
            ->and($invoice->shipments)->toBeEmpty()
            ->and((float) $invoice->total_amount)->toBe(115.0);

        Notification::assertSentTo($this->accountant, ShipmentRequestNotification::class);

        $this->actingAs($this->accountant)->get(route('shipping.deliveries'))->assertInertia(fn ($page) => $page->where('deliveries.data', []));
    });

    it('adds the approved fee as a balance on the paid invoice and collects it without re-approving', function () {
        ($this->request)($this->employee)->assertSessionHasNoErrors();
        $pending = $this->invoice->pendingShipments()->sole();

        $this->actingAs($this->employee)
            ->patch(route('invoices.service.shipment-requests.approve', [$this->invoice, $pending]))
            ->assertForbidden();

        $this->actingAs($this->accountant)
            ->patch(route('invoices.service.shipment-requests.approve', [$this->invoice, $pending]))
            ->assertSessionHasNoErrors();

        $invoice = $this->invoice->fresh();
        $paidAt = $invoice->paid_at->toIso8601String();

        // ما حُصِّل عند الاعتماد صار صفّاً بيومه، والرسم متبقٍّ والفاتورة ما تزال مدفوعة.
        expect($invoice->status)->toBe(InvoiceStatusEnum::PAID)
            ->and((float) $invoice->total_amount)->toBe(138.0)
            ->and((float) $invoice->shipping_fee)->toBe(23.0)
            ->and((float) $invoice->vat_amount)->toBe(18.0)
            ->and($invoice->shipments)->toHaveCount(1)
            ->and($invoice->payments()->sole()->only(['amount', 'payment_method_id']))->toEqual(['amount' => '115.00', 'payment_method_id' => $this->cash->id])
            ->and($invoice->remainingAmount())->toBe(23.0)
            ->and($invoice->owesAfterApproval())->toBeTrue();

        // السائق يعود بالرسم: دفعةٌ عادية تُغلق المتبقي، والعمولة لا تُكتب ثانيةً.
        $this->actingAs($this->accountant)
            ->post(route('invoices.payments.store', ['type' => 'service', 'id' => $invoice->id]), ['amount' => 23, 'payment_method_id' => $this->cash->id])
            ->assertSessionHasNoErrors();

        $invoice->refresh();
        expect($invoice->status)->toBe(InvoiceStatusEnum::PAID)
            ->and($invoice->remainingAmount())->toBe(0.0)
            ->and($invoice->paid_at->toIso8601String())->toBe($paidAt)
            ->and(CommissionLedger::count())->toBe(1);

        // ولا دفعة بعدها: المتبقي صفر.
        $this->actingAs($this->accountant)
            ->post(route('invoices.payments.store', ['type' => 'service', 'id' => $invoice->id]), ['amount' => 1, 'payment_method_id' => $this->cash->id])
            ->assertForbidden();
    });

    it('does not count an uncollected fee as collected when the invoice is edited', function () {
        ($this->request)($this->accountant)->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)
            ->patch(route('invoices.service.shipment-requests.approve', [$this->invoice, $this->invoice->pendingShipments()->sole()]));

        $invoice = $this->invoice->fresh();
        $invoice->settleAfterEdit($this->accountant->id, (float) $invoice->total_amount);

        expect($invoice->payments()->count())->toBe(1)
            ->and($invoice->remainingAmount())->toBe(23.0);
    });

    it('records a free delivery without touching the invoice total', function () {
        ($this->request)($this->accountant, ['fee' => 0])->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)
            ->patch(route('invoices.service.shipment-requests.approve', [$this->invoice, $this->invoice->pendingShipments()->sole()]))
            ->assertSessionHasNoErrors();

        $invoice = $this->invoice->fresh();
        expect((float) $invoice->total_amount)->toBe(115.0)
            ->and($invoice->payments()->count())->toBe(0)
            ->and($invoice->shipments)->toHaveCount(1);
    });

    it('lets the accountant reject a request with a reason the requester receives', function () {
        Notification::fake();
        ($this->request)($this->employee)->assertSessionHasNoErrors();
        $pending = $this->invoice->pendingShipments()->sole();

        $this->actingAs($this->accountant)
            ->delete(route('invoices.service.shipment-requests.reject', [$this->invoice, $pending]), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->accountant)
            ->delete(route('invoices.service.shipment-requests.reject', [$this->invoice, $pending]), ['reason' => 'العميل سيستلمها بنفسه'])
            ->assertSessionHasNoErrors();

        expect($this->invoice->pendingShipments()->exists())->toBeFalse();
        Notification::assertSentTo($this->employee, ShipmentRequestNotification::class);
    });

    it('refuses a request on an invoice that is not approved yet', function () {
        ServiceInvoice::whereKey($this->invoice->id)->update(['status' => InvoiceStatusEnum::DUE]);

        ($this->request)($this->employee)->assertForbidden();
    });
});
