<?php

use App\Models\ProductCategory;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('ProductCategory Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole('branch-admin');
        $this->actingAs($this->branchAdmin);
    });

    // ── INDEX ──────────────────────────────────────────────────────

    it('allows branch-admin to view product category list', function () {
        ProductCategory::factory()->count(3)->create();

        $this->get(route('product-categories.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('product-categories/index'));
    });

    it('allows super-admin to view product category list', function () {
        $superAdmin = User::factory()->create();
        $superAdmin->addRole('super-admin');
        $this->actingAs($superAdmin);

        $this->get(route('product-categories.index'))->assertOk();
    });

    it('prevents employee from viewing product category list', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->get(route('product-categories.index'))->assertForbidden();
    });

    // ── STORE ──────────────────────────────────────────────────────

    it('creates a product category with valid data', function () {
        $this->post(route('product-categories.store'), [
            'name'      => 'ورق طباعة',
            'is_active' => true,
        ])->assertRedirect(route('product-categories.index'));

        $this->assertDatabaseHas('product_categories', [
            'name'      => 'ورق طباعة',
            'is_active' => true,
        ]);
    });

    it('fails to create a product category without a name', function () {
        $this->post(route('product-categories.store'), [])
            ->assertSessionHasErrors(['name']);
    });

    it('fails to create a product category with a name exceeding 255 characters', function () {
        $this->post(route('product-categories.store'), ['name' => str_repeat('أ', 256)])
            ->assertSessionHasErrors(['name']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('updates a product category name', function () {
        $category = ProductCategory::factory()->create();

        $this->put(route('product-categories.update', $category), ['name' => 'أحبار وأصباغ'])
            ->assertRedirect(route('product-categories.index'));

        $this->assertDatabaseHas('product_categories', [
            'id'   => $category->id,
            'name' => 'أحبار وأصباغ',
        ]);
    });

    // ── TOGGLE STATUS ──────────────────────────────────────────────

    it('toggles product category status from active to inactive', function () {
        $category = ProductCategory::factory()->create(['is_active' => true]);

        $this->patch(route('product-categories.toggle-status', $category))
            ->assertRedirect(route('product-categories.index'));

        $this->assertDatabaseHas('product_categories', ['id' => $category->id, 'is_active' => false]);
    });

    it('toggles product category status from inactive to active', function () {
        $category = ProductCategory::factory()->create(['is_active' => false]);

        $this->patch(route('product-categories.toggle-status', $category))
            ->assertRedirect(route('product-categories.index'));

        $this->assertDatabaseHas('product_categories', ['id' => $category->id, 'is_active' => true]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('deletes a product category not referenced by any product', function () {
        $category = ProductCategory::factory()->create();

        $this->delete(route('product-categories.destroy', $category))
            ->assertRedirect(route('product-categories.index'));

        $this->assertDatabaseMissing('product_categories', ['id' => $category->id]);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('prevents accountant from creating product categories', function () {
        $accountant = User::factory()->create();
        $accountant->addRole('accountant');
        $this->actingAs($accountant);

        $this->post(route('product-categories.store'), ['name' => 'فئة جديدة'])
            ->assertForbidden();
    });
});
