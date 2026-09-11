<?php

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ExpenseCategory Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole('branch-admin');
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);
        $this->actingAs($this->branchAdmin);
    });

    // ── INDEX ──────────────────────────────────────────────────────

    it('allows branch-admin to view expense category list', function () {
        ExpenseCategory::factory()->count(3)->create();

        $this->get(route('expense-categories.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('expense-categories/index'));
    });

    it('allows super-admin to view expense category list', function () {
        $superAdmin = User::factory()->create();
        $superAdmin->addRole('super-admin');
        $this->actingAs($superAdmin);

        $this->get(route('expense-categories.index'))->assertOk();
    });

    it('prevents employee from viewing expense category list', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->get(route('expense-categories.index'))->assertForbidden();
    });

    // ── STORE ──────────────────────────────────────────────────────

    it('creates an expense category with valid data', function () {
        $this->post(route('expense-categories.store'), [
            'name' => 'إيجار',
            'is_active' => true,
        ])->assertRedirect(route('expense-categories.index'));

        $this->assertDatabaseHas('expense_categories', [
            'name' => 'إيجار',
            'is_active' => true,
            'branch_id' => $this->branch->id,
        ]);
    });

    it('fails to create an expense category without a name', function () {
        $this->post(route('expense-categories.store'), [])
            ->assertSessionHasErrors(['name']);
    });

    it('fails to create an expense category with a duplicate name', function () {
        ExpenseCategory::factory()->create(['name' => 'كهرباء']);

        $this->post(route('expense-categories.store'), ['name' => 'كهرباء'])
            ->assertSessionHasErrors(['name']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('updates an expense category name', function () {
        $category = ExpenseCategory::factory()->create(['branch_id' => $this->branch->id]);

        $this->put(route('expense-categories.update', $category), ['name' => 'صيانة'])
            ->assertRedirect(route('expense-categories.index'));

        $this->assertDatabaseHas('expense_categories', [
            'id' => $category->id,
            'name' => 'صيانة',
        ]);
    });

    // ── TOGGLE STATUS ──────────────────────────────────────────────

    it('toggles expense category status from active to inactive', function () {
        $category = ExpenseCategory::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);

        $this->patch(route('expense-categories.toggle-status', $category))
            ->assertRedirect(route('expense-categories.index'));

        $this->assertDatabaseHas('expense_categories', ['id' => $category->id, 'is_active' => false]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('deletes an expense category not referenced by any expense', function () {
        $category = ExpenseCategory::factory()->create(['branch_id' => $this->branch->id]);

        $this->delete(route('expense-categories.destroy', $category))
            ->assertRedirect(route('expense-categories.index'));

        $this->assertDatabaseMissing('expense_categories', ['id' => $category->id]);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('prevents accountant from creating expense categories', function () {
        $accountant = User::factory()->create();
        $accountant->addRole('accountant');
        $this->actingAs($accountant);

        $this->post(route('expense-categories.store'), ['name' => 'فئة جديدة'])
            ->assertForbidden();
    });

    // ── تاسك 102: فئات لكل فرع ─────────────────────────────────────

    describe('per-branch categories', function () {
        beforeEach(function () {
            $this->otherAdmin = User::factory()->create();
            $this->otherAdmin->addRole('branch-admin');
            $this->otherBranch = Branch::factory()->create(['owner_id' => $this->otherAdmin->id]);
            $this->otherAdmin->update(['branch_id' => $this->otherBranch->id]);
        });

        it('hides another branch\'s category and forbids touching it', function () {
            $theirs = ExpenseCategory::factory()->create(['name' => 'صيانة', 'branch_id' => $this->otherBranch->id]);
            $global = ExpenseCategory::factory()->create(['name' => 'إيجار']);
            $mine = ExpenseCategory::factory()->create(['name' => 'قرطاسية', 'branch_id' => $this->branch->id]);

            $this->get(route('expense-categories.index'))
                ->assertInertia(fn ($page) => $page
                    ->has('items.data', 2)
                    ->where('items.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === [$global->id, $mine->id]));

            $this->put(route('expense-categories.update', $theirs), ['name' => 'x'])->assertForbidden();
            $this->patch(route('expense-categories.toggle-status', $theirs))->assertForbidden();
            $this->delete(route('expense-categories.destroy', $theirs))->assertForbidden();
        });

        it('lets two branches each create the same name, but not one that shadows a global category', function () {
            ExpenseCategory::factory()->create(['name' => 'إيجار']);

            $this->post(route('expense-categories.store'), ['name' => 'كهرباء'])->assertSessionHasNoErrors();
            $this->actingAs($this->otherAdmin)
                ->post(route('expense-categories.store'), ['name' => 'كهرباء', 'branch_id' => $this->branch->id])
                ->assertSessionHasNoErrors();

            // branch_id في الطلب يُتجاهل لمدير الفرع.
            expect(ExpenseCategory::where('name', 'كهرباء')->pluck('branch_id')->sort()->values()->all())
                ->toBe([$this->branch->id, $this->otherBranch->id]);

            $this->post(route('expense-categories.store'), ['name' => 'إيجار'])->assertSessionHasErrors('name');
        });

        it('shows a global category to both branches while only the super-admin edits it', function () {
            $global = ExpenseCategory::factory()->create(['name' => 'إيجار']);

            foreach ([$this->branchAdmin, $this->otherAdmin] as $admin) {
                $this->actingAs($admin)
                    ->get(route('expense-categories.index'))
                    ->assertInertia(fn ($page) => $page->where('items.data.0.id', $global->id)->where('items.data.0.canEdit', false));

                $this->put(route('expense-categories.update', $global), ['name' => 'x'])->assertForbidden();
            }

            $superAdmin = User::factory()->create();
            $superAdmin->addRole('super-admin');

            $this->actingAs($superAdmin)
                ->put(route('expense-categories.update', $global), ['name' => 'إيجار المحل'])
                ->assertSessionHasNoErrors();

            $this->post(route('expense-categories.store'), ['name' => 'وقود', 'branch_id' => $this->otherBranch->id])
                ->assertSessionHasNoErrors();
            expect(ExpenseCategory::firstWhere('name', 'وقود')->branch_id)->toBe($this->otherBranch->id);
        });

        it('rejects an expense booked under another branch\'s category', function () {
            $theirs = ExpenseCategory::factory()->create(['branch_id' => $this->otherBranch->id]);
            $mine = ExpenseCategory::factory()->create(['branch_id' => $this->branch->id]);
            $payload = ['qty' => 1, 'unit_price' => 50, 'date' => today()->toDateString()];

            $this->post(route('expenses.store'), [...$payload, 'expense_category_id' => $theirs->id])
                ->assertSessionHasErrors('expense_category_id');

            $this->post(route('expenses.store'), [...$payload, 'expense_category_id' => $mine->id])
                ->assertSessionHasNoErrors();

            $expense = Expense::firstOrFail();
            $this->put(route('expenses.update', $expense), [...$payload, 'expense_category_id' => $theirs->id])
                ->assertSessionHasErrors('expense_category_id');
        });
    });
});
