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

        $category = ExpenseCategory::firstWhere('name', 'إيجار');
        expect($category->is_active)->toBeTrue()
            ->and($category->branchIds())->toBe([$this->branch->id]);
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
        $category = ExpenseCategory::factory()->forBranches($this->branch->id)->create();

        $this->put(route('expense-categories.update', $category), ['name' => 'صيانة'])
            ->assertRedirect(route('expense-categories.index'));

        $this->assertDatabaseHas('expense_categories', [
            'id' => $category->id,
            'name' => 'صيانة',
        ]);
    });

    // ── TOGGLE STATUS ──────────────────────────────────────────────

    it('toggles expense category status from active to inactive', function () {
        $category = ExpenseCategory::factory()->forBranches($this->branch->id)->create(['is_active' => true]);

        $this->patch(route('expense-categories.toggle-status', $category))
            ->assertRedirect(route('expense-categories.index'));

        $this->assertDatabaseHas('expense_categories', ['id' => $category->id, 'is_active' => false]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('deletes an expense category not referenced by any expense', function () {
        $category = ExpenseCategory::factory()->forBranches($this->branch->id)->create();

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
            $theirs = ExpenseCategory::factory()->forBranches($this->otherBranch->id)->create(['name' => 'صيانة']);
            $global = ExpenseCategory::factory()->create(['name' => 'إيجار']);
            $mine = ExpenseCategory::factory()->forBranches($this->branch->id)->create(['name' => 'قرطاسية']);

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
                ->post(route('expense-categories.store'), ['name' => 'كهرباء', 'branch_ids' => [$this->branch->id]])
                ->assertSessionHasNoErrors();

            // branch_ids في الطلب تُتجاهل لمدير الفرع.
            expect(ExpenseCategory::where('name', 'كهرباء')->get()->map->branchIds()->sort()->values()->all())
                ->toBe([[$this->branch->id], [$this->otherBranch->id]]);

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

            $this->post(route('expense-categories.store'), ['name' => 'وقود', 'branch_ids' => [$this->otherBranch->id]])
                ->assertSessionHasNoErrors();
            expect(ExpenseCategory::firstWhere('name', 'وقود')->branchIds())->toBe([$this->otherBranch->id]);
        });

        // ── تاسك 231: السوبر أدمن ينقل النطاق عند التعديل ──────────────

        it('lets the super-admin move a category between a branch and global', function () {
            $superAdmin = User::factory()->create();
            $superAdmin->addRole('super-admin');
            $this->actingAs($superAdmin);

            $category = ExpenseCategory::factory()->forBranches($this->branch->id)->create(['name' => 'صيانة']);
            Expense::factory()->create(['expense_category_id' => $category->id, 'branch_id' => $this->branch->id]);

            // فرع ⇒ عامة: مسموح دائماً.
            $this->put(route('expense-categories.update', $category), ['name' => 'صيانة', 'branch_ids' => []])
                ->assertSessionHasNoErrors();
            expect($category->fresh()->branchIds())->toBe([]);

            // طلبٌ بلا branch_ids (الاسم وحده) لا يغيّر النطاق.
            $this->put(route('expense-categories.update', $category), ['name' => 'صيانة الأجهزة'])->assertSessionHasNoErrors();
            expect($category->fresh()->branchIds())->toBe([]);

            // عامة ⇒ فرعٍ آخر ولها مصروف في الأول: مرفوض، والرسالة تسمّي الفرع الناقص.
            $this->put(route('expense-categories.update', $category), ['name' => 'صيانة', 'branch_ids' => [$this->otherBranch->id]])
                ->assertSessionHasErrors(['branch_ids' => 'لها مصروفات في: '.$this->branch->name.' — أضِفها إلى النطاق أو اتركها عامة.']);
            expect($category->fresh()->branchIds())->toBe([]);

            // تاسك 238: عامة ⇒ الفرعين معاً: مسموح، ويراها الاثنان.
            $this->put(route('expense-categories.update', $category), ['name' => 'صيانة', 'branch_ids' => [$this->branch->id, $this->otherBranch->id]])
                ->assertSessionHasNoErrors();
            expect(collect($category->fresh()->branchIds())->sort()->values()->all())->toBe([$this->branch->id, $this->otherBranch->id]);

            foreach ([$this->branchAdmin, $this->otherAdmin] as $admin) {
                $this->actingAs($admin)->get(route('expense-categories.index'))
                    ->assertInertia(fn ($page) => $page->where('items.data.0.id', $category->id)->where('items.data.0.canEdit', false));
            }
        });

        it('measures the name against the new scope and ignores a branch admin\'s branch_id', function () {
            $mine = ExpenseCategory::factory()->forBranches($this->branch->id)->create(['name' => 'كهرباء']);
            ExpenseCategory::factory()->forBranches($this->otherBranch->id)->create(['name' => 'كهرباء']);

            // مدير الفرع يرسل branch_ids ⇒ لا تتغيّر.
            $this->put(route('expense-categories.update', $mine), ['name' => 'كهرباء', 'branch_ids' => [$this->otherBranch->id]])
                ->assertSessionHasNoErrors();
            expect($mine->fresh()->branchIds())->toBe([$this->branch->id]);

            $superAdmin = User::factory()->create();
            $superAdmin->addRole('super-admin');

            // اسمٌ مكرّر في النطاق الجديد.
            $this->actingAs($superAdmin)
                ->put(route('expense-categories.update', $mine), ['name' => 'كهرباء', 'branch_ids' => [$this->branch->id, $this->otherBranch->id]])
                ->assertSessionHasErrors('name');
        });

        it('rejects an expense booked under another branch\'s category', function () {
            $theirs = ExpenseCategory::factory()->forBranches($this->otherBranch->id)->create();
            $mine = ExpenseCategory::factory()->forBranches($this->branch->id)->create();
            $payload = ['qty' => 1, 'unit_price' => 50, 'paid_from' => 'cash_drawer', 'date' => today()->toDateString()];

            $this->post(route('expenses.store'), [...$payload, 'expense_category_id' => $theirs->id])
                ->assertSessionHasErrors('expense_category_id');

            $this->post(route('expenses.store'), [...$payload, 'expense_category_id' => $mine->id])
                ->assertSessionHasNoErrors();

            $expense = Expense::firstOrFail();
            $this->put(route('expenses.update', $expense), [...$payload, 'expense_category_id' => $theirs->id])
                ->assertSessionHasErrors('expense_category_id');
        });

        // تاسك 240: العامة المعطّلة تخصّ السوبر أدمن — مدير الفرع لا يراها، ويرى معطّلة فرعه.
        it('hides inactive global categories from a branch admin only', function () {
            $globalOff = ExpenseCategory::factory()->create(['name' => 'سحبيات', 'is_active' => false]);
            $globalOn = ExpenseCategory::factory()->create(['name' => 'صيانة']);
            $mineOff = ExpenseCategory::factory()->forBranches($this->branch->id)->create(['name' => 'ضيافة', 'is_active' => false]);
            $ids = fn ($page) => collect($page->toArray()['props']['items']['data'])->pluck('id')->sort()->values()->all();

            $this->get(route('expense-categories.index'))
                ->assertInertia(fn ($page) => expect($ids($page))->toBe(collect([$globalOn->id, $mineOff->id])->sort()->values()->all()));

            $superAdmin = User::factory()->create();
            $superAdmin->addRole('super-admin');
            $this->actingAs($superAdmin)->get(route('expense-categories.index'))
                ->assertInertia(fn ($page) => expect($ids($page))->toContain($globalOff->id, $globalOn->id, $mineOff->id));
        });
    });
});
