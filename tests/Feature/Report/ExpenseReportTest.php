<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** مصروف بمبلغ وتاريخ محدَّدين — الإجمالي يُثبَّت لأن التقرير يجمعه لا يشتقّه. */
function reportExpense(Branch $branch, User $user, ExpenseCategory $category, float $total, string $date): Expense
{
    return Expense::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'expense_category_id' => $category->id,
        'qty' => 1,
        'unit_price' => $total,
        'total' => $total,
        'date' => $date,
    ]);
}

describe('Expense Report', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->otherBranch = Branch::factory()->create();

        // فئات المصروفات جدول عام بلا branch_id.
        $this->rent = ExpenseCategory::factory()->create(['name' => 'إيجار']);
        $this->supplies = ExpenseCategory::factory()->create(['name' => 'قرطاسية']);

        $this->today = now()->toDateString();
    });

    // ── ACCESS ─────────────────────────────────────────────────────

    it('lets a super-admin view the report', function () {
        $this->actingAs($this->superAdmin)
            ->get(route('reports.expenses'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/expenses/index')
                ->has('totals')
                ->has('byCategory')
                ->has('byDay')
                ->has('expenses')
                ->where('isSuperAdmin', true));
    });

    it('lets an accountant and a branch admin view the report', function () {
        $this->actingAs($this->accountant)->get(route('reports.expenses'))->assertOk();
        $this->actingAs($this->branchAdmin)->get(route('reports.expenses'))->assertOk();
    });

    it('forbids an employee from viewing the report', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)->get(route('reports.expenses'))->assertForbidden();
    });

    // ── FIGURES ────────────────────────────────────────────────────

    it('totals the rows it lists', function () {
        reportExpense($this->branch, $this->accountant, $this->rent, 1200.00, $this->today);
        reportExpense($this->branch, $this->accountant, $this->supplies, 300.00, $this->today);
        reportExpense($this->branch, $this->accountant, $this->supplies, 100.00, $this->today);

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.expenses'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                expect((float) $props['totals']['total'])->toBe(1600.00)
                    ->and($props['totals']['expenseCount'])->toBe(3)
                    ->and(round((float) $props['totals']['average'], 2))->toBe(533.33)
                    // مجموع صفوف الجدول = الإجمالي المعروض، فلا يختلف رأس التقرير عن جسمه.
                    ->and((float) collect($props['expenses'])->sum('total'))->toBe(1600.00);
            });
    });

    it('breaks the spend down by category, biggest first, with its share', function () {
        reportExpense($this->branch, $this->accountant, $this->rent, 750.00, $this->today);
        reportExpense($this->branch, $this->accountant, $this->supplies, 250.00, $this->today);

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.expenses'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $rows = collect($page->toArray()['props']['byCategory']);

                expect($rows->pluck('name')->all())->toBe(['إيجار', 'قرطاسية'])
                    ->and((float) $rows[0]['total'])->toBe(750.00)
                    ->and((float) $rows[0]['pct'])->toBe(75.0)
                    ->and((float) $rows[1]['pct'])->toBe(25.0)
                    // أعلى فئة على البطاقة هي أول صفوف التفصيل.
                    ->and($page->toArray()['props']['totals']['topCategoryName'])->toBe('إيجار');
            });
    });

    it('dates a spend by its expense date, not by when it was entered', function () {
        // سُجّل اليوم عن يوم أمس — يقع في يوم صرفه لا في يوم إدخاله.
        reportExpense($this->branch, $this->accountant, $this->rent, 500.00, now()->subDay()->toDateString());

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.expenses'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totals.expenseCount', 0));

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.expenses', ['from' => now()->subDay()->toDateString(), 'to' => now()->subDay()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totals.expenseCount', 1));
    });

    it('keeps a zero row for every quiet day inside a filtered range', function () {
        reportExpense($this->branch, $this->accountant, $this->rent, 400.00, now()->subDays(2)->toDateString());

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.expenses', ['from' => now()->subDays(2)->toDateString(), 'to' => $this->today]))
            ->assertOk()
            ->assertInertia(function ($page) {
                $days = collect($page->toArray()['props']['byDay']);

                expect($days)->toHaveCount(3)
                    ->and((float) $days[0]['total'])->toBe(400.00)
                    ->and((float) $days[1]['total'])->toBe(0.0);
            });
    });

    it('leaves a soft-deleted expense out of the figures', function () {
        $expense = reportExpense($this->branch, $this->accountant, $this->rent, 900.00, $this->today);
        $expense->delete();

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.expenses'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totals.expenseCount', 0));
    });

    // ── SCOPING ────────────────────────────────────────────────────

    it('scopes an accountant to their own branch', function () {
        reportExpense($this->branch, $this->accountant, $this->rent, 100.00, $this->today);

        $otherUser = User::factory()->create(['branch_id' => $this->otherBranch->id]);
        $otherCategory = ExpenseCategory::factory()->create();
        reportExpense($this->otherBranch, $otherUser, $otherCategory, 5000.00, $this->today);

        $this->actingAs($this->accountant)
            ->get(route('reports.expenses'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totals.expenseCount', 1)
                ->where('totals.total', fn ($v) => (float) $v === 100.00));
    });

    it('lets a super-admin filter by branch', function () {
        reportExpense($this->branch, $this->accountant, $this->rent, 100.00, $this->today);

        $otherUser = User::factory()->create(['branch_id' => $this->otherBranch->id]);
        $otherCategory = ExpenseCategory::factory()->create();
        reportExpense($this->otherBranch, $otherUser, $otherCategory, 5000.00, $this->today);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.expenses', ['branch' => $this->otherBranch->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totals.total', fn ($v) => (float) $v === 5000.00));
    });

    it('filters by expense category', function () {
        reportExpense($this->branch, $this->accountant, $this->rent, 700.00, $this->today);
        reportExpense($this->branch, $this->accountant, $this->supplies, 200.00, $this->today);

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.expenses', ['category' => $this->supplies->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totals.expenseCount', 1)
                ->where('totals.total', fn ($v) => (float) $v === 200.00));
    });

    // ── EXPORT ─────────────────────────────────────────────────────

    it('exports the same rows the screen lists', function () {
        reportExpense($this->branch, $this->accountant, $this->rent, 120.00, $this->today);
        reportExpense($this->branch, $this->accountant, $this->supplies, 80.00, $this->today);

        $response = $this->actingAs($this->branchAdmin)
            ->get(route('reports.expenses.export'));

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('expense-report-');
    });

    it('forbids an employee from exporting', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)->get(route('reports.expenses.export'))->assertForbidden();
    });
});
