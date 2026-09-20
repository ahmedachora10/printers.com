<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * تاسك 113 — المحاسب يسجّل ويعدّل ويحذف قبل الاعتماد وحده؛ مدير الفرع يعتمد
 * (فردياً أو كل ما تحت الفلاتر) ويعدّل المعتمد بسجلّ القديم والجديد.
 */
describe('Expense approval', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->category = ExpenseCategory::factory()->create();

        $this->makeExpense = fn (array $overrides = []) => Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $this->category->id,
            'user_id' => $this->accountant->id,
            'date' => today()->toDateString(),
            'approved_at' => null,
            ...$overrides,
        ]);

        $this->payload = [
            'expense_category_id' => $this->category->id,
            'qty' => 2,
            'unit_price' => 10,
            'paid_from' => 'cash_drawer',
            'date' => today()->toDateString(),
        ];
    });

    it('lets the accountant edit and delete until the expense is approved', function () {
        $expense = ($this->makeExpense)();

        $this->actingAs($this->accountant)->put(route('expenses.update', $expense), $this->payload)->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('expenses.approve', $expense))->assertForbidden();

        $this->actingAs($this->branchAdmin)->post(route('expenses.approve', $expense))->assertRedirect();
        expect($expense->fresh())
            ->isApproved()->toBeTrue()
            ->approved_by->toBe($this->branchAdmin->id);

        $this->actingAs($this->accountant)->put(route('expenses.update', $expense), $this->payload)->assertForbidden();
        $this->actingAs($this->accountant)->delete(route('expenses.destroy', $expense))->assertForbidden();
    });

    it('lets the branch admin edit an approved expense and logs old and new values', function () {
        $expense = ($this->makeExpense)(['qty' => 1, 'unit_price' => 10, 'total' => 10]);
        $rent = ExpenseCategory::factory()->create(['name' => 'إيجار']);
        $this->actingAs($this->branchAdmin)->post(route('expenses.approve', $expense));

        $this->actingAs($this->branchAdmin)
            ->put(route('expenses.update', $expense), [...$this->payload, 'qty' => 3, 'expense_category_id' => $rent->id])
            ->assertSessionHasNoErrors();

        $log = Activity::query()->forSubject($expense)->where('event', 'updated')->latest('id')->firstOrFail();
        expect((float) $log->properties['old']['total'])->toBe(10.0)
            ->and((float) $log->properties['attributes']['total'])->toBe(30.0)
            ->and($log->causer_id)->toBe($this->branchAdmin->id);

        $this->actingAs($this->branchAdmin)->get(route('expenses.index'))
            ->assertInertia(fn ($page) => $page
                ->has('items.data.0.history', 1)
                ->where('items.data.0.history.0.old.expense_category_id', $this->category->name)
                ->where('items.data.0.history.0.new.expense_category_id', 'إيجار'));
    });

    it('lets the branch admin unapprove, handing the expense back to the accountant', function () {
        $expense = ($this->makeExpense)(['approved_at' => now(), 'approved_by' => $this->branchAdmin->id]);

        $this->actingAs($this->accountant)->post(route('expenses.unapprove', $expense))->assertForbidden();
        $this->actingAs($this->branchAdmin)->post(route('expenses.unapprove', $expense))->assertRedirect();

        expect($expense->fresh()->isApproved())->toBeFalse();
        $this->actingAs($this->accountant)->put(route('expenses.update', $expense), $this->payload)->assertSessionHasNoErrors();
    });

    /**
     * تاسك 123 يعكس 113: «المصروف يُحتسب سواء تم اعتماده أم لم يتم» — زرّ الاعتماد
     * يثبّت القيد ويقفل التعديل، ولا يُدخله الحسابات.
     */
    it('counts unapproved expenses in the reports, flagged but not excluded', function () {
        ($this->makeExpense)(['total' => 40]);
        ($this->makeExpense)(['total' => 60, 'approved_at' => now()]);

        $this->actingAs($this->branchAdmin)->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page->where('totals.expenses', 100));
        $this->actingAs($this->branchAdmin)->get(route('reports.expenses'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.total', 100)
                ->where('totals.pendingCount', 1)
                ->where('totals.pendingTotal', 40));
        $this->actingAs($this->branchAdmin)->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.purchases', 100));
    });

    it('still locks an approved expense against the accountant', function () {
        $expense = ($this->makeExpense)(['approved_at' => now()]);

        $this->actingAs($this->accountant)
            ->put(route('expenses.update', $expense), $this->payload)
            ->assertForbidden();

        $this->actingAs($this->accountant)
            ->delete(route('expenses.destroy', $expense))
            ->assertForbidden();
    });

    it('narrows the screen and «approve all» to one source when the filter is set', function () {
        $cash = ($this->makeExpense)(['paid_from' => 'cash_drawer']);
        $transfer = ($this->makeExpense)(['paid_from' => 'company_transfer']);

        $this->actingAs($this->branchAdmin)
            ->get(route('expenses.index', ['paid_from' => 'cash_drawer']))
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $cash->id)
                ->where('filters.paid_from', 'cash_drawer')
                ->where('pendingSummary.count', 1));

        // الفلتر يمرّ عبر filteredQuery المشتركة، فزرّ «اعتماد الكل» يحترمه.
        $this->actingAs($this->branchAdmin)
            ->post(route('expenses.approve-all', ['paid_from' => 'cash_drawer']))
            ->assertRedirect();

        expect($cash->fresh()->isApproved())->toBeTrue()
            ->and($transfer->fresh()->isApproved())->toBeFalse();
    });

    it('approves every pending expense under the current filters and nothing else', function () {
        $today = ($this->makeExpense)();
        $yesterday = ($this->makeExpense)(['date' => today()->subDay()->toDateString()]);

        $otherAdmin = User::factory()->create();
        $otherAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $otherBranch = Branch::factory()->create(['owner_id' => $otherAdmin->id]);
        $foreign = ($this->makeExpense)(['branch_id' => $otherBranch->id]);

        $this->actingAs($this->accountant)->post(route('expenses.approve-all'))->assertForbidden();

        $this->actingAs($this->branchAdmin)->get(route('expenses.index'))
            ->assertInertia(fn ($page) => $page->where('pendingSummary.count', 1));

        $this->actingAs($this->branchAdmin)->post(route('expenses.approve-all'))->assertRedirect();

        expect($today->fresh()->isApproved())->toBeTrue()
            ->and($yesterday->fresh()->isApproved())->toBeFalse()
            ->and($foreign->fresh()->isApproved())->toBeFalse();

        $this->actingAs($this->branchAdmin)->get(route('expenses.index', ['approval' => 'pending', 'range' => 'all']))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('items.data.0.id', $yesterday->id));
    });
});
