<?php

use App\Enums\Roles;
use App\Models\AccountReconciliation;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// تاسك 121 — مطابقة الحسابات: الأجهزة المُدخلة + التلقائي − صافي النظام.
describe('Account reconciliation', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);
        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);
        $this->otherBranch = Branch::factory()->create();

        $this->mada = PaymentMethod::factory()->network()->create(['name' => 'مدى']);
        $this->rajhi = PaymentMethod::factory()->create(['name' => 'الراجحي']);
        $this->cash = PaymentMethod::factory()->cash()->create(['name' => 'نقد']);

        $this->sell = fn (PaymentMethod $method, float $total) => ServiceInvoice::create([
            'invoice_number' => 'SINV-T-'.fake()->unique()->numberBetween(1, 999999),
            'branch_id' => $this->branch->id,
            'user_id' => $this->branchAdmin->id,
            'subtotal' => $total,
            'vat_pct' => 0,
            'vat_amount' => 0,
            'total_amount' => $total,
            'employee_commission' => 0,
            'status' => 'paid',
            'paid_at' => now(),
            'payment_method_id' => $method->id,
        ]);

        $this->save = fn (User $as, array $amounts, array $extra = []) => $this->actingAs($as)
            ->post(route('finance.reconciliation.store'), [
                'date' => today()->toDateString(),
                'devices' => array_map(fn ($a, $i) => [
                    'payment_method_id' => $this->mada->id,
                    'device_label' => 'جهاز '.($i + 1),
                    'amount' => $a,
                ], $amounts, array_keys($amounts)),
                ...$extra,
            ]);
    });

    // أمثلة العميل الثلاثة حرفياً: صافي 1000، التلقائي 500 (الراجحي)، الشبكة 500 في النظام.
    it('reports matched, shortage and surplus', function (array $devices, int $difference) {
        ($this->sell)($this->mada, 500);
        ($this->sell)($this->rajhi, 500);

        ($this->save)($this->accountant, $devices)->assertRedirect()->assertSessionHasNoErrors();
        $reconciliation = AccountReconciliation::sole();

        $this->actingAs($this->branchAdmin)
            ->post(route('finance.reconciliation.approve', $reconciliation))
            ->assertRedirect();

        $this->actingAs($this->accountant)
            ->get(route('finance.reconciliation.index'))
            ->assertInertia(fn ($page) => $page
                ->component('finance/reconciliation/index')
                ->where('figures.systemNet', 1000)
                ->where('figures.autoTotal', 500)
                ->where('figures.frozen', true)
                ->where('history.data.0.difference', $difference));
    })->with([
        'مطابق — جهازان لنفس الطريقة' => [[200, 300], 0],
        'عجز 100' => [[400], -100],
        'زيادة 100' => [[600], 100],
    ]);

    it('pulls net cash automatically, cash expenses included', function () {
        ($this->sell)($this->cash, 300);
        ($this->sell)($this->mada, 200);
        Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->accountant->id,
            'expense_category_id' => ExpenseCategory::factory(),
            'total' => 50,
            'paid_from' => 'cash_drawer',
            'date' => today()->toDateString(),
        ]);

        $this->actingAs($this->accountant)
            ->get(route('finance.reconciliation.index'))
            ->assertInertia(fn ($page) => $page
                ->where('figures.systemNet', 450)
                ->where('figures.autoTotal', 250)
                ->where('figures.frozen', false)
                ->where('reconciliation', null));
    });

    it('freezes the system figures at approval', function () {
        ($this->sell)($this->rajhi, 500);
        ($this->save)($this->accountant, [0]);
        $this->actingAs($this->branchAdmin)->post(route('finance.reconciliation.approve', AccountReconciliation::sole()));

        ($this->sell)($this->rajhi, 999);

        $this->actingAs($this->accountant)
            ->get(route('finance.reconciliation.index'))
            ->assertInertia(fn ($page) => $page->where('figures.systemNet', 500));

        // الإلغاء يعيدها حيّة.
        $this->actingAs($this->branchAdmin)->post(route('finance.reconciliation.unapprove', AccountReconciliation::sole()));
        $this->actingAs($this->accountant)
            ->get(route('finance.reconciliation.index'))
            ->assertInertia(fn ($page) => $page->where('figures.systemNet', 1499)->where('figures.frozen', false));
    });

    it('locks an approved reconciliation and keeps approval from the accountant', function () {
        ($this->save)($this->accountant, [100]);
        $reconciliation = AccountReconciliation::sole();

        $this->actingAs($this->accountant)
            ->post(route('finance.reconciliation.approve', $reconciliation))
            ->assertForbidden();

        $this->actingAs($this->branchAdmin)->post(route('finance.reconciliation.approve', $reconciliation));

        ($this->save)($this->accountant, [999])->assertSessionHasErrors('devices');
        expect((float) $reconciliation->fresh()->devices_total)->toBe(100.0);
    });

    it('pins the accountant to their branch and accepts network methods only', function () {
        ($this->save)($this->accountant, [100], ['branch' => $this->otherBranch->id]);
        expect(AccountReconciliation::sole()->branch_id)->toBe($this->branch->id);

        $this->actingAs($this->accountant)
            ->post(route('finance.reconciliation.store'), [
                'date' => today()->toDateString(),
                'devices' => [['payment_method_id' => $this->rajhi->id, 'device_label' => 'x', 'amount' => 10]],
            ])
            ->assertSessionHasErrors('devices.0.payment_method_id');
    });

    it('keeps another branch\'s reconciliation from its branch admin', function () {
        $other = AccountReconciliation::create([
            'branch_id' => $this->otherBranch->id,
            'date' => today()->toDateString(),
            'created_by' => $this->branchAdmin->id,
        ]);

        $this->actingAs($this->branchAdmin)
            ->post(route('finance.reconciliation.approve', $other))
            ->assertForbidden();
    });

    // تاسك 144: مثال العميل — زيادة وعجزان ⇒ الفرق عجز؛ غير المعتمد وخارج المدى لا يدخلان.
    it('totals the approved surplus and shortage within the history range', function () {
        $row = fn (string $date, float $devices, bool $approved = true) => AccountReconciliation::create([
            'branch_id' => $this->branch->id,
            'date' => $date,
            'created_by' => $this->branchAdmin->id,
            'system_net' => 1000,
            'auto_total' => 0,
            'devices_total' => $devices,
            'approved_at' => $approved ? now() : null,
        ]);
        $row('2026-09-01', 1100);
        $row('2026-09-02', 950);
        $row('2026-09-03', 919.95);
        $row('2026-09-04', 500, approved: false);
        $row('2026-08-31', 5000);

        $this->actingAs($this->branchAdmin)
            ->get(route('finance.reconciliation.index', ['history_from' => '2026-09-01', 'history_to' => '2026-09-30']))
            ->assertInertia(fn ($page) => $page
                ->where('historyTotals.surplus', 100)
                ->where('historyTotals.shortage', 130.05)
                ->where('historyTotals.net', -30.05)
                ->where('historyTotals.pending', 1)
                ->has('history.data', 4));
    });
});
