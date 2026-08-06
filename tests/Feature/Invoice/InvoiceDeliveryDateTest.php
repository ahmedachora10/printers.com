<?php

use App\Console\Commands\NotifyUpcomingDeliveriesCommand;
use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Notifications\UpcomingDeliveryNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * موعد تسليم العمل (delivery_at) على فاتورة الخدمة: يُحفظ ويُسترجع، ويُرفض
 * الموعد الماضي، ويظهر في المورد وفي قائمة الفواتير وفلترها، ويُذكَّر به صاحب
 * الفاتورة قبل الموعد بيوم.
 */
function deliveryBranchService(int $branchId): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create([
        'branch_id' => $branchId,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'is_tahazir' => false,
        'is_active' => true,
    ]);

    // BranchService is a Pivot (non-incrementing), so create() doesn't hydrate
    // the id — re-fetch so callers get a model with its primary key populated.
    return BranchService::where('branch_id', $branchId)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

/** فاتورة خدمة جاهزة بموعد تسليم — لا يوجد factory لـ ServiceInvoice. */
function deliveryInvoice(int $branchId, int $userId, mixed $deliveryAt, InvoiceStatusEnum $status = InvoiceStatusEnum::DUE): ServiceInvoice
{
    return ServiceInvoice::create([
        'invoice_number' => 'SINV-DLV-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branchId,
        'user_id' => $userId,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 15,
        'total_amount' => 115,
        'employee_commission' => 10,
        'status' => $status,
        'delivery_at' => $deliveryAt,
    ]);
}

/** معرّفات الفواتير التي أعادتها قائمة الفواتير لهذا الطلب. */
function deliveryListIds(TestResponse $response): array
{
    $ids = [];

    $response->assertOk()->assertInertia(function ($page) use (&$ids) {
        $ids = collect($page->toArray()['props']['items']['data'])->pluck('id')->all();
    });

    return $ids;
}

describe('Service invoice delivery date', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->service = deliveryBranchService($this->branch->id);

        $this->lines = [
            ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
        ];
    });

    it('saves the delivery date and time on a service invoice', function () {
        // بعد غد الساعة 14:30 — موعد صالح في المستقبل.
        $deliveryAt = now()->addDays(2)->setTime(14, 30);

        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'delivery_at' => $deliveryAt->format('Y-m-d H:i'),
                'lines' => $this->lines,
            ])
            ->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        // يُخزَّن بدقّة الدقيقة، بالثواني مصفّرة.
        expect($invoice->delivery_at->format('Y-m-d H:i:s'))->toBe($deliveryAt->format('Y-m-d H:i').':00');
    });

    it('keeps the delivery date empty when none is chosen', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'lines' => $this->lines,
            ])
            ->assertRedirect();

        expect(ServiceInvoice::firstOrFail()->delivery_at)->toBeNull();
    });

    it('rejects a delivery date in the past', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'delivery_at' => now()->subDay()->format('Y-m-d H:i'),
                'lines' => $this->lines,
            ])
            ->assertSessionHasErrors('delivery_at');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('accepts a delivery date later today', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'delivery_at' => now()->format('Y-m-d').' 23:59',
                'lines' => $this->lines,
            ])
            ->assertSessionHasNoErrors();

        expect(ServiceInvoice::firstOrFail()->delivery_at)->not->toBeNull();
    });

    it('loads the delivery date back into the POS edit screen and updates it', function () {
        $original = now()->addDays(2)->setTime(9, 0);

        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'delivery_at' => $original->format('Y-m-d H:i'),
                'lines' => $this->lines,
            ])
            ->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        $this->actingAs($this->employee)
            ->get(route('pos.service.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos/service/index')
                ->where('invoice.deliveryAt', $original->format('Y-m-d H:i')));

        $moved = now()->addDays(5)->setTime(16, 45);

        $this->actingAs($this->employee)
            ->put(route('pos.service.update', $invoice), [
                'delivery_at' => $moved->format('Y-m-d H:i'),
                'lines' => $this->lines,
            ])
            ->assertRedirect();

        expect($invoice->refresh()->delivery_at->format('Y-m-d H:i'))->toBe($moved->format('Y-m-d H:i'));
    });

    it('clears the delivery date when the edit submits an empty picker', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'delivery_at' => now()->addDay()->format('Y-m-d H:i'),
                'lines' => $this->lines,
            ])
            ->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        $this->actingAs($this->employee)
            ->put(route('pos.service.update', $invoice), [
                'delivery_at' => null,
                'lines' => $this->lines,
            ])
            ->assertRedirect();

        expect($invoice->refresh()->delivery_at)->toBeNull();
    });

    it('exposes the delivery date and its status on the invoice resource', function () {
        $deliveryAt = now()->addDays(3)->setTime(11, 0);

        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'delivery_at' => $deliveryAt->format('Y-m-d H:i'),
                'lines' => $this->lines,
            ])
            ->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        $this->actingAs($this->employee)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/show')
                ->where('invoice.deliveryAt', $invoice->delivery_at->toIso8601String())
                ->where('invoice.deliveryStatus', 'upcoming'));

        // ورقة الطباعة تحمل الموعد كذلك.
        $this->actingAs($this->employee)
            ->get(route('invoices.print', ['type' => 'service', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('invoices/print')
                ->where('invoice.deliveryAt', $invoice->delivery_at->toIso8601String()));

        $this->actingAs($this->employee)
            ->get(route('pos.service.print', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos/service/print')
                ->where('invoice.deliveryAt', $invoice->delivery_at->toIso8601String()));
    });

    it('marks an overdue invoice red-worthy and a today invoice amber-worthy in the list', function () {
        $overdue = deliveryInvoice($this->branch->id, $this->employee->id, now()->subDays(2)->setTime(10, 0));
        $dueToday = deliveryInvoice($this->branch->id, $this->employee->id, now()->setTime(23, 0));

        $this->actingAs($this->employee)
            ->get(route('invoices.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($overdue, $dueToday) {
                $statuses = collect($page->toArray()['props']['items']['data'])
                    ->pluck('deliveryStatus', 'id');

                expect($statuses[$overdue->id])->toBe('overdue')
                    ->and($statuses[$dueToday->id])->toBe('today');
            });
    });

    it('drops the delivery status of a returned invoice — nothing left to deliver', function () {
        $returned = deliveryInvoice(
            $this->branch->id,
            $this->employee->id,
            now()->subDays(2)->setTime(10, 0),
            InvoiceStatusEnum::RETURNED,
        );

        expect($returned->deliveryStatus())->toBeNull();
    });

    it('filters the list to today\'s and to overdue deliveries', function () {
        $overdue = deliveryInvoice($this->branch->id, $this->employee->id, now()->subDay()->setTime(10, 0));
        $dueToday = deliveryInvoice($this->branch->id, $this->employee->id, now()->setTime(20, 0));

        // موعدها بعيد — لا يلتقطها أيّ من الفلترين.
        deliveryInvoice($this->branch->id, $this->employee->id, now()->addDays(4)->setTime(10, 0));

        // ملغاة ومتأخرة — لا ينتظر أحد تسليمها.
        deliveryInvoice(
            $this->branch->id,
            $this->employee->id,
            now()->subDays(3)->setTime(10, 0),
            InvoiceStatusEnum::CANCELLED,
        );

        $ids = fn (string $delivery) => deliveryListIds(
            $this->actingAs($this->employee)->get(route('invoices.index', ['delivery' => $delivery])),
        );

        expect($ids('today'))->toBe([$dueToday->id])
            ->and($ids('overdue'))->toBe([$overdue->id]);
    });

    it('reminds the owning employee one day before the delivery, once', function () {
        Notification::fake();

        $tomorrow = deliveryInvoice($this->branch->id, $this->employee->id, now()->addDay()->setTime(10, 0));

        // بعد غد — خارج نطاق التذكير.
        deliveryInvoice($this->branch->id, $this->employee->id, now()->addDays(2)->setTime(10, 0));

        $this->artisan(NotifyUpcomingDeliveriesCommand::class)->assertSuccessful();

        Notification::assertSentToTimes($this->employee, UpcomingDeliveryNotification::class, 1);
        Notification::assertSentTo(
            $this->employee,
            fn (UpcomingDeliveryNotification $notification) => str_contains(
                $notification->toArray($this->employee)['body'],
                $tomorrow->invoice_number,
            ),
        );
    });

    it('does not repeat the reminder when the command runs again the same day', function () {
        deliveryInvoice($this->branch->id, $this->employee->id, now()->addDay()->setTime(10, 0));

        $this->artisan(NotifyUpcomingDeliveriesCommand::class)->assertSuccessful();
        $this->artisan(NotifyUpcomingDeliveriesCommand::class)->assertSuccessful();

        expect($this->employee->notifications()->count())->toBe(1);
    });
});
