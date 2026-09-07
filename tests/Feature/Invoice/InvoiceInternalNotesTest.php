<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * تاسك 95 — «ملاحظات داخلية» ثالثةٌ لا تُطبع، بجوار «ملاحظات للعميل» (تاسك 26)
 * وتفصيل السطر (تاسك 5) وكلاهما مطبوع. الإخفاء بـCSS لا يكفي: حمولة Inertia
 * تصل المتصفح كاملةً، فالحذف على الخادم.
 */
function internalNotesInvoice(int $branchId, int $userId, array $overrides = []): ServiceInvoice
{
    $invoice = ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-INT-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branchId,
        'user_id' => $userId,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 13.04,
        'total_amount' => 100,
        'employee_commission' => 0,
        'status' => InvoiceStatusEnum::PAID,
        'paid_at' => now(),
        'notes' => 'يُسلَّم بعد ثلاثة أيام',
        'internal_notes' => 'الخامة من مخزون الفرع الثاني — نبّه المحاسب',
    ], $overrides));

    ServiceInvoiceLine::create([
        'invoice_id' => $invoice->id,
        'service_name' => 'طباعة',
        'qty' => 1,
        'unit_price' => 100,
        'discount_pct' => 0,
        'subtotal' => 100,
        'commission_pct' => 0,
        'commission_amount' => 0,
    ]);

    return $invoice;
}

describe('Invoice internal notes', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->addRole(Roles::EMPLOYEE->value);

        $this->otherEmployee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->otherEmployee->addRole(Roles::EMPLOYEE->value);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->method = PaymentMethod::factory()->create(['name' => 'نقد']);
        $this->invoice = internalNotesInvoice($this->branch->id, $this->owner->id, ['payment_method_id' => $this->method->id]);
    });

    // ── العرض ────────────────────────────────────────────────────

    it('shows the note to the accountant and to the employee who wrote the invoice', function () {
        foreach ([$this->accountant, $this->branchAdmin, $this->owner] as $user) {
            $this->actingAs($user)
                ->get(route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]))
                ->assertInertia(fn ($page) => $page
                    ->where('invoice.internalNotes', 'الخامة من مخزون الفرع الثاني — نبّه المحاسب')
                    ->where('invoice.canEditInternalNotes', true));
        }
    });

    it('withholds it from an employee whose invoice it is not', function () {
        $this->actingAs($this->otherEmployee)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.internalNotes', null)
                ->where('invoice.canEditInternalNotes', false)
                // وملاحظة العميل تبقى ظاهرة — الحقلان مستقلّان.
                ->where('invoice.notes', 'يُسلَّم بعد ثلاثة أيام'));
    });

    // ── الطباعة ──────────────────────────────────────────────────

    it('never reaches a print payload, for any role', function () {
        foreach ([$this->branchAdmin, $this->accountant, $this->owner] as $user) {
            $this->actingAs($user)
                ->get(route('invoices.print', ['type' => 'service', 'id' => $this->invoice->id]))
                ->assertInertia(fn ($page) => $page
                    ->where('invoice.internalNotes', null)
                    // وملاحظة العميل تُطبع كما كانت منذ تاسك 26.
                    ->where('invoice.notes', 'يُسلَّم بعد ثلاثة أيام'));
        }
    });

    it('keeps the text out of the print response body entirely', function () {
        $body = $this->actingAs($this->accountant)
            ->get(route('invoices.print', ['type' => 'service', 'id' => $this->invoice->id]))
            ->getContent();

        expect($body)->not->toContain('نبّه المحاسب');
    });

    // ── التحرير ──────────────────────────────────────────────────

    it('lets a reviewer correct the note after the invoice was approved', function () {
        $this->actingAs($this->accountant)
            ->patch(route('invoices.internal-notes', ['type' => 'service', 'id' => $this->invoice->id]), [
                'internal_notes' => 'صُحّحت: الخامة من مخزون الفرع نفسه',
            ])
            ->assertRedirect();

        expect($this->invoice->refresh()->internal_notes)->toBe('صُحّحت: الخامة من مخزون الفرع نفسه')
            // والمال لا يُمسّ: هي تعليمات تنفيذ لا رقمٌ مالي.
            ->and($this->invoice->status)->toBe(InvoiceStatusEnum::PAID)
            ->and((float) $this->invoice->total_amount)->toBe(100.0);

        expect(Activity::where('log_name', 'invoices')
            ->where('description', 'updated internal notes')->count())->toBe(1);
    });

    it('clears the note when an empty string is sent', function () {
        $this->actingAs($this->branchAdmin)
            ->patch(route('invoices.internal-notes', ['type' => 'service', 'id' => $this->invoice->id]), [
                'internal_notes' => '   ',
            ])
            ->assertRedirect();

        expect($this->invoice->refresh()->internal_notes)->toBeNull();
    });

    it('forbids an employee from editing a note on an invoice that is not theirs', function () {
        $this->actingAs($this->otherEmployee)
            ->patch(route('invoices.internal-notes', ['type' => 'service', 'id' => $this->invoice->id]), [
                'internal_notes' => 'تعديل غير مصرّح',
            ])
            ->assertForbidden();

        expect($this->invoice->refresh()->internal_notes)->toBe('الخامة من مخزون الفرع الثاني — نبّه المحاسب');
    });

    it('refuses to touch the note on a cancelled invoice', function () {
        $cancelled = internalNotesInvoice($this->branch->id, $this->owner->id, [
            'status' => InvoiceStatusEnum::CANCELLED,
            'paid_at' => null,
        ]);

        $this->actingAs($this->accountant)
            ->patch(route('invoices.internal-notes', ['type' => 'service', 'id' => $cancelled->id]), [
                'internal_notes' => 'متأخر',
            ])
            ->assertForbidden();
    });

    // ── نقطة البيع ───────────────────────────────────────────────

    it('saves the note straight from the service POS without touching the customer note', function () {
        $branchService = BranchService::query()->firstOr(function () {
            $template = ServiceTemplate::factory()->create(['name' => 'تغليف']);
            $template->branches()->attach($this->branch->id, ['base_commission_pct' => 0, 'is_active' => true]);

            return BranchService::query()
                ->where('branch_id', $this->branch->id)
                ->where('service_template_id', $template->id)
                ->firstOrFail();
        });

        $this->actingAs($this->owner)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'notes' => 'للعميل',
                'internal_notes' => 'داخلية',
                'lines' => [[
                    'branch_service_id' => $branchService->id,
                    'qty' => 1,
                    'unit_price' => 50,
                    'discount_pct' => 0,
                ]],
            ])
            ->assertRedirect();

        $created = ServiceInvoice::latest('id')->first();

        expect($created->notes)->toBe('للعميل')
            ->and($created->internal_notes)->toBe('داخلية');
    });
});
