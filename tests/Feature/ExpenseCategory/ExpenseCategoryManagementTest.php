<?php

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
        $category = ExpenseCategory::factory()->create();

        $this->put(route('expense-categories.update', $category), ['name' => 'صيانة'])
            ->assertRedirect(route('expense-categories.index'));

        $this->assertDatabaseHas('expense_categories', [
            'id' => $category->id,
            'name' => 'صيانة',
        ]);
    });

    // ── TOGGLE STATUS ──────────────────────────────────────────────

    it('toggles expense category status from active to inactive', function () {
        $category = ExpenseCategory::factory()->create(['is_active' => true]);

        $this->patch(route('expense-categories.toggle-status', $category))
            ->assertRedirect(route('expense-categories.index'));

        $this->assertDatabaseHas('expense_categories', ['id' => $category->id, 'is_active' => false]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('deletes an expense category not referenced by any expense', function () {
        $category = ExpenseCategory::factory()->create();

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
});
