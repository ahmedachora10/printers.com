<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * اليوم التجاري بتوقيت الرياض (تاسك 33).
 *
 * تحت UTC كان اليوم يبدأ الساعة ٣:٠٠ فجراً بتوقيت الرياض، ففاتورة تُحرَّر
 * ١٢:٠١ ص تُخزَّن بيوم الأمس وتغيب عن تقرير اليوم. هذه الاختبارات تثبّت أن
 * المنطقة الزمنية صارت Asia/Riyadh وأن حدود اليوم في التقارير تبعتها.
 */
function riyadhInvoice(Branch $branch, User $user): ServiceInvoice
{
    return ServiceInvoice::create([
        'invoice_number' => 'SINV-TZ-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 13.04,
        'total_amount' => 100,
        'employee_commission' => 10,
        'status' => 'paid',
        'paid_at' => now(),
    ]);
}

describe('Riyadh business day', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
    });

    it('runs the application on Riyadh time', function () {
        expect(config('app.timezone'))->toBe('Asia/Riyadh')
            ->and(now()->getTimezone()->getName())->toBe('Asia/Riyadh');
    });

    it('starts the day at midnight Riyadh, not at 3 AM', function () {
        // ١٢:٣٠ ص بتوقيت الرياض = 21:30 من اليوم السابق بتوقيت UTC.
        $this->travelTo(Carbon::parse('2026-08-10 00:30', 'Asia/Riyadh'));

        expect(Carbon::today()->toDateString())->toBe('2026-08-10')
            ->and(now()->format('Y-m-d H:i'))->toBe('2026-08-10 00:30');
    });

    it('files an invoice raised at 12:01 AM under that same day in the daily report', function () {
        $this->travelTo(Carbon::parse('2026-08-10 00:01', 'Asia/Riyadh'));

        $invoice = riyadhInvoice($this->branch, $this->employee);

        expect($invoice->created_at->toDateString())->toBe('2026-08-10');

        // التقرير بلا فلاتر يفتح على «اليوم» — ويجب أن يجد الفاتورة فيه.
        $this->actingAs($this->admin)
            ->get(route('reports.daily'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('defaultDate', '2026-08-10')
                ->where('rows.0.date', '2026-08-10')
                // شاملة الضريبة منذ التاسك 58 — 100 لا 86.96.
                ->where('totals.services', fn ($v) => round((float) $v, 2) === 100.0));

        // ولا أثر لها في تقرير الأمس.
        $this->actingAs($this->admin)
            ->get(route('reports.daily', ['from' => '2026-08-09', 'to' => '2026-08-09']))
            ->assertOk()
            // Inertia يُرسل 0.0 رقماً صحيحاً، فتُقارَن القيمة بعد التحويل.
            ->assertInertia(fn ($page) => $page->where('totals.services', fn ($v) => (float) $v === 0.0));
    });

    it('files the same invoice under that day in the sales report', function () {
        $this->travelTo(Carbon::parse('2026-08-10 00:01', 'Asia/Riyadh'));

        riyadhInvoice($this->branch, $this->employee);

        $this->actingAs($this->admin)
            ->get(route('reports.sales'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('defaultDate', '2026-08-10')
                ->where('totals.invoiceCount', 1)
                ->where('byDay.0.date', '2026-08-10'));
    });
});
