<?php

use App\Console\Commands\NotifyUpcomingDeliveriesCommand;
use App\Enums\DeliveryStatusEnum;
use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * زر «تم تسليم العمل» (تاسك 31): ختم delivered_at/delivered_by على فاتورة
 * الخدمة، وأسبقيته على حالة موعد التسليم، ومَن يملك ختمه.
 */
function deliverableInvoice(int $branchId, int $userId, array $overrides = []): ServiceInvoice
{
    return ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-DEL-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branchId,
        'user_id' => $userId,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 13.04,
        'total_amount' => 100,
        'employee_commission' => 10,
        'status' => InvoiceStatusEnum::DUE,
        'delivery_at' => now()->addDay()->setTime(10, 0),
    ], $overrides));
}

describe('Marking service invoice work delivered', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);
    });

    // ── الختم ────────────────────────────────────────────────────

    it('stamps who delivered the work and when', function () {
        $invoice = deliverableInvoice($this->branch->id, $this->employee->id);

        $this->actingAs($this->employee)
            ->post(route('invoices.service.deliver', $invoice))
            ->assertRedirect();

        $invoice->refresh();

        expect($invoice->delivered_at)->not->toBeNull()
            ->and((int) $invoice->delivered_by)->toBe($this->employee->id)
            // التسليم لا يمسّ المال: الفاتورة الآجلة تبقى آجلة.
            ->and($invoice->status)->toBe(InvoiceStatusEnum::DUE);
    });

    it('refuses to deliver the same invoice twice', function () {
        $invoice = deliverableInvoice($this->branch->id, $this->employee->id, ['delivered_at' => now()->subHour()]);

        $this->actingAs($this->employee)
            ->post(route('invoices.service.deliver', $invoice))
            ->assertForbidden();
    });

    it('refuses to deliver a returned invoice', function () {
        $invoice = deliverableInvoice($this->branch->id, $this->employee->id, ['status' => InvoiceStatusEnum::RETURNED]);

        $this->actingAs($this->employee)
            ->post(route('invoices.service.deliver', $invoice))
            ->assertForbidden();

        expect($invoice->refresh()->delivered_at)->toBeNull();
    });

    it('refuses to deliver a cancelled invoice', function () {
        $invoice = deliverableInvoice($this->branch->id, $this->employee->id, ['status' => InvoiceStatusEnum::CANCELLED]);

        $this->actingAs($this->employee)
            ->post(route('invoices.service.deliver', $invoice))
            ->assertForbidden();
    });

    // ── الصلاحية ─────────────────────────────────────────────────

    it('lets the accountant and the branch admin deliver too', function () {
        $forAccountant = deliverableInvoice($this->branch->id, $this->employee->id);
        $forAdmin = deliverableInvoice($this->branch->id, $this->employee->id);

        $this->actingAs($this->accountant)
            ->post(route('invoices.service.deliver', $forAccountant))
            ->assertRedirect();

        $this->actingAs($this->branchAdmin)
            ->post(route('invoices.service.deliver', $forAdmin))
            ->assertRedirect();

        expect($forAccountant->refresh()->delivered_at)->not->toBeNull()
            ->and($forAdmin->refresh()->delivered_at)->not->toBeNull();
    });

    it('forbids an employee from delivering an invoice that is not theirs', function () {
        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole(Roles::EMPLOYEE->value);

        $invoice = deliverableInvoice($this->branch->id, $other->id);

        $this->actingAs($this->employee)
            ->post(route('invoices.service.deliver', $invoice))
            ->assertForbidden();
    });

    it('forbids an accountant from delivering an invoice of another branch', function () {
        $otherBranch = Branch::factory()->create();
        $otherEmployee = User::factory()->create(['branch_id' => $otherBranch->id]);
        $otherEmployee->addRole(Roles::EMPLOYEE->value);

        $invoice = deliverableInvoice($otherBranch->id, $otherEmployee->id);

        $this->actingAs($this->accountant)
            ->post(route('invoices.service.deliver', $invoice))
            ->assertForbidden();
    });

    // ── الحالة المشتقّة ──────────────────────────────────────────

    it('lets the delivered stamp win over an overdue appointment', function () {
        $invoice = deliverableInvoice($this->branch->id, $this->employee->id, [
            'delivery_at' => now()->subDays(3),
            'delivered_at' => now(),
        ]);

        expect($invoice->deliveryStatus())->toBe(DeliveryStatusEnum::DELIVERED);
    });

    it('carries the delivered status and stamp on the invoice resource', function () {
        $invoice = deliverableInvoice($this->branch->id, $this->employee->id);

        $this->actingAs($this->employee)
            ->post(route('invoices.service.deliver', $invoice))
            ->assertRedirect();

        $this->actingAs($this->employee)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.deliveryStatus', 'delivered')
                ->where('invoice.deliveredAt', $invoice->refresh()->delivered_at->toIso8601String())
                ->where('invoice.deliveredByName', $this->employee->name)
                // الزر يختفي بعد التسليم — الصلاحية وحدها تقرّر.
                ->where('invoice.canDeliver', false));
    });

    it('offers the deliver button on a live invoice and hides it once delivered', function () {
        $live = deliverableInvoice($this->branch->id, $this->employee->id);
        $delivered = deliverableInvoice($this->branch->id, $this->employee->id, ['delivered_at' => now()]);

        $this->actingAs($this->employee)
            ->get(route('invoices.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($live, $delivered) {
                $rows = collect($page->toArray()['props']['items']['data'])->keyBy('id');

                expect($rows[$live->id]['canDeliver'])->toBeTrue()
                    ->and($rows[$delivered->id]['canDeliver'])->toBeFalse()
                    ->and($rows[$delivered->id]['deliveryStatus'])->toBe('delivered');
            });
    });

    // ── الفلتر والتذكير ──────────────────────────────────────────

    it('isolates delivered invoices in the list filter', function () {
        $delivered = deliverableInvoice($this->branch->id, $this->employee->id, [
            'delivery_at' => now()->subDays(2),
            'delivered_at' => now(),
        ]);

        // متأخرة ولم تُسلَّم — تخرج من «تم التسليم» وتبقى في «متأخر».
        $overdue = deliverableInvoice($this->branch->id, $this->employee->id, ['delivery_at' => now()->subDay()]);

        $ids = fn (string $delivery) => collect(
            $this->actingAs($this->employee)
                ->get(route('invoices.index', ['delivery' => $delivery]))
                ->assertOk()
                ->viewData('page')['props']['items']['data'],
        )->pluck('id')->all();

        expect($ids('delivered'))->toBe([$delivered->id])
            ->and($ids('overdue'))->toBe([$overdue->id]);
    });

    it('does not remind about work that was already delivered', function () {
        Notification::fake();

        deliverableInvoice($this->branch->id, $this->employee->id, [
            'delivery_at' => now()->addDay()->setTime(10, 0),
            'delivered_at' => now(),
        ]);

        $this->artisan(NotifyUpcomingDeliveriesCommand::class)->assertSuccessful();

        Notification::assertNothingSentTo($this->employee);
    });
});
