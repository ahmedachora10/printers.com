<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Expense Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchAdmin);

        $this->category = ExpenseCategory::factory()->create();
    });

    // ── INDEX ──────────────────────────────────────────────────────

    it('allows branch-admin to view expense list', function () {
        Expense::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $this->category->id,
            'user_id' => $this->branchAdmin->id,
        ]);

        $this->get(route('expenses.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('expenses/index'));
    });

    it('allows accountant to view expense list', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        $this->get(route('expenses.index'))->assertOk();
    });

    it('prevents employee from viewing expense list', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($employee);

        $this->get(route('expenses.index'))->assertForbidden();
    });

    // ── STORE ──────────────────────────────────────────────────────

    it('creates an expense and computes the total', function () {
        $this->post(route('expenses.store'), [
            'expense_category_id' => $this->category->id,
            'qty' => 3,
            'unit_price' => 25.50,
            'supplier_name' => 'مكتبة الرياض',
            'receipt_reference' => 'REF-100',
            'date' => '2026-06-10',
        ])->assertRedirect(route('expenses.index'));

        $this->assertDatabaseHas('expenses', [
            'expense_category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->branchAdmin->id,
            'qty' => 3,
            'unit_price' => 25.50,
            'total' => 76.50,
            'supplier_name' => 'مكتبة الرياض',
        ]);
    });

    it('fails to create an expense without a category', function () {
        $this->post(route('expenses.store'), [
            'qty' => 1,
            'unit_price' => 10,
            'date' => '2026-06-10',
        ])->assertSessionHasErrors(['expense_category_id']);
    });

    it('fails to create an expense without a date', function () {
        $this->post(route('expenses.store'), [
            'expense_category_id' => $this->category->id,
            'qty' => 1,
            'unit_price' => 10,
        ])->assertSessionHasErrors(['date']);
    });

    it('allows super-admin to create an expense for a chosen branch', function () {
        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);
        $this->actingAs($superAdmin);

        $this->post(route('expenses.store'), [
            'branch_id' => $this->branch->id,
            'expense_category_id' => $this->category->id,
            'qty' => 2,
            'unit_price' => 30,
            'date' => '2026-06-10',
        ])->assertRedirect(route('expenses.index'));

        $this->assertDatabaseHas('expenses', [
            'branch_id' => $this->branch->id,
            'user_id' => $superAdmin->id,
            'total' => 60,
        ]);
    });

    it('fails when super-admin creates an expense without a branch', function () {
        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);
        $this->actingAs($superAdmin);

        $this->post(route('expenses.store'), [
            'expense_category_id' => $this->category->id,
            'qty' => 1,
            'unit_price' => 10,
            'date' => '2026-06-10',
        ])->assertSessionHasErrors(['branch_id']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('updates an expense and recomputes the total', function () {
        $expense = Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $this->category->id,
            'user_id' => $this->branchAdmin->id,
        ]);

        $this->put(route('expenses.update', $expense), [
            'expense_category_id' => $this->category->id,
            'qty' => 4,
            'unit_price' => 10,
            'date' => '2026-06-11',
        ])->assertRedirect(route('expenses.index'));

        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'qty' => 4,
            'total' => 40,
        ]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('soft deletes an expense', function () {
        $expense = Expense::factory()->create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $this->category->id,
            'user_id' => $this->branchAdmin->id,
        ]);

        $this->delete(route('expenses.destroy', $expense))
            ->assertRedirect(route('expenses.index'));

        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('prevents employee from creating expenses', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($employee);

        $this->post(route('expenses.store'), [
            'expense_category_id' => $this->category->id,
            'qty' => 1,
            'unit_price' => 10,
            'date' => '2026-06-10',
        ])->assertForbidden();
    });
});
