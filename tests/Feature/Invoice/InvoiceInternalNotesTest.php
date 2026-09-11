<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * تاسك 95 — «ملاحظات داخلية» ثالثةٌ لا تُطبع، بجوار «ملاحظات للعميل» (تاسك 26).
 * الإخفاء بـCSS لا يكفي: حمولة Inertia تصل المتصفح كاملةً، فالحذف على الخادم.
 *
 * منذ تاسك 100 صار الحقل لفواتير المنتجات وحدها — فاتورة الخدمات لها محادثة
 * داخلية (InvoiceMessageTest).
 */
function internalNotesInvoice(int $branchId, int $userId, array $overrides = []): ProductInvoice
{
    return ProductInvoice::create(array_merge([
        'invoice_number' => 'INV-INT-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branchId,
        'user_id' => $userId,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 13.04,
        'total_amount' => 100,
        'status' => InvoiceStatusEnum::PAID,
        'paid_at' => now(),
        'notes' => 'يُسلَّم بعد ثلاثة أيام',
        'internal_notes' => 'الخامة من مخزون الفرع الثاني — نبّه المحاسب',
    ], $overrides));
}

describe('Invoice internal notes (product invoices)', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->invoice = internalNotesInvoice($this->branch->id, $this->accountant->id);
    });

    it('shows the note to the reviewers', function () {
        foreach ([$this->accountant, $this->branchAdmin] as $user) {
            $this->actingAs($user)
                ->get(route('invoices.show', ['type' => 'product', 'id' => $this->invoice->id]))
                ->assertInertia(fn ($page) => $page
                    ->where('invoice.internalNotes', 'الخامة من مخزون الفرع الثاني — نبّه المحاسب')
                    ->where('invoice.canEditInternalNotes', true)
                    ->where('hasThread', false));
        }
    });

    it('never reaches a print payload', function () {
        $response = $this->actingAs($this->accountant)
            ->get(route('invoices.print', ['type' => 'product', 'id' => $this->invoice->id]));

        $response->assertInertia(fn ($page) => $page
            ->where('invoice.internalNotes', null)
            ->where('invoice.notes', 'يُسلَّم بعد ثلاثة أيام'));

        expect($response->getContent())->not->toContain('نبّه المحاسب');
    });

    it('lets a reviewer correct the note after the invoice was approved', function () {
        $this->actingAs($this->accountant)
            ->patch(route('invoices.internal-notes', ['type' => 'product', 'id' => $this->invoice->id]), [
                'internal_notes' => 'صُحّحت: الخامة من مخزون الفرع نفسه',
            ])
            ->assertRedirect();

        expect($this->invoice->refresh()->internal_notes)->toBe('صُحّحت: الخامة من مخزون الفرع نفسه')
            ->and($this->invoice->status)->toBe(InvoiceStatusEnum::PAID);

        expect(Activity::where('log_name', 'invoices')
            ->where('description', 'updated internal notes')->count())->toBe(1);
    });

    it('clears the note when an empty string is sent', function () {
        $this->actingAs($this->branchAdmin)
            ->patch(route('invoices.internal-notes', ['type' => 'product', 'id' => $this->invoice->id]), [
                'internal_notes' => '   ',
            ])
            ->assertRedirect();

        expect($this->invoice->refresh()->internal_notes)->toBeNull();
    });

    it('refuses to touch the note on a cancelled invoice', function () {
        $cancelled = internalNotesInvoice($this->branch->id, $this->accountant->id, [
            'status' => InvoiceStatusEnum::CANCELLED,
            'paid_at' => null,
        ]);

        $this->actingAs($this->accountant)
            ->patch(route('invoices.internal-notes', ['type' => 'product', 'id' => $cancelled->id]), [
                'internal_notes' => 'متأخر',
            ])
            ->assertForbidden();
    });

    it('no longer edits the column on a service invoice — that is the thread now', function () {
        $service = ServiceInvoice::create([
            'invoice_number' => 'SINV-INT-1',
            'branch_id' => $this->branch->id,
            'user_id' => $this->accountant->id,
            'subtotal' => 100,
            'vat_pct' => 15,
            'vat_amount' => 13.04,
            'total_amount' => 100,
            'employee_commission' => 0,
            'status' => InvoiceStatusEnum::PAID,
            'internal_notes' => 'قديمة',
        ]);

        // المسار لم يعد يطابق «service» — ويردّ الـfallback بـ405 لا 404.
        $response = $this->actingAs($this->accountant)
            ->patch('/invoices/service/'.$service->id.'/internal-notes', ['internal_notes' => 'جديدة']);

        expect($response->status())->toBeIn([404, 405])
            ->and($service->refresh()->internal_notes)->toBe('قديمة');

        $this->actingAs($this->accountant)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $service->id]))
            ->assertInertia(fn ($page) => $page
                ->where('invoice.internalNotes', null)
                ->where('invoice.canEditInternalNotes', false)
                ->where('hasThread', true));
    });
});
