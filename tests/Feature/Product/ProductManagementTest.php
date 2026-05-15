<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\User;
use App\Enums\Roles;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Product Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchAdmin);

        $this->category = ProductCategory::factory()->create();
        $this->unit     = ProductUnit::factory()->create();
    });

    // ── INDEX ──────────────────────────────────────────────────────

    it('allows branch-admin to view product list', function () {
        Product::factory()->count(3)->create([
            'branch_id'   => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id'     => $this->unit->id,
        ]);

        $this->get(route('inventory.products.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('inventory/products/index'));
    });

    it('allows super-admin to view product list', function () {
        $superAdmin = User::factory()->create();
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);
        $this->actingAs($superAdmin);

        $this->get(route('inventory.products.index'))->assertOk();
    });

    it('prevents employee from viewing product list', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($employee);

        $this->get(route('inventory.products.index'))->assertForbidden();
    });

    // ── STORE ──────────────────────────────────────────────────────

    it('creates a product with valid data', function () {
        $this->post(route('inventory.products.store'), [
            'sku'             => 'SKU-1-001',
            'name'            => 'ورق طباعة A4',
            'category_id'     => $this->category->id,
            'unit_id'         => $this->unit->id,
            'branch_id'       => $this->branch->id,
            'cost_price'      => 10.00,
            'selling_price'   => 15.00,
            'min_stock_level' => 5,
            'is_active'       => true,
        ])->assertRedirect(route('inventory.products.index'));

        $this->assertDatabaseHas('products', [
            'sku'       => 'SKU-1-001',
            'name'      => 'ورق طباعة A4',
            'branch_id' => $this->branch->id,
        ]);
    });

    it('auto-generates SKU when not provided', function () {
        $this->post(route('inventory.products.store'), [
            'sku'           => '',
            'name'          => 'حبر طابعة',
            'category_id'   => $this->category->id,
            'unit_id'       => $this->unit->id,
            'branch_id'     => $this->branch->id,
            'cost_price'    => 20.00,
            'selling_price' => 35.00,
            'is_active'     => true,
        ])->assertRedirect(route('inventory.products.index'));

        $product = Product::where('name', 'حبر طابعة')->first();
        expect($product->sku)->toStartWith('SKU-');
    });

    it('fails to create a product without required fields', function () {
        $this->post(route('inventory.products.store'), [])
            ->assertSessionHasErrors(['name', 'category_id', 'unit_id', 'cost_price', 'selling_price']);
    });

    it('fails to create a product with duplicate SKU in same branch', function () {
        Product::factory()->create([
            'branch_id'   => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id'     => $this->unit->id,
            'sku'         => 'DUPE-001',
        ]);

        $this->post(route('inventory.products.store'), [
            'sku'           => 'DUPE-001',
            'name'          => 'منتج آخر',
            'category_id'   => $this->category->id,
            'unit_id'       => $this->unit->id,
            'branch_id'     => $this->branch->id,
            'cost_price'    => 5.00,
            'selling_price' => 10.00,
        ])->assertSessionHasErrors(['sku']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('updates a product', function () {
        $product = Product::factory()->create([
            'branch_id'   => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id'     => $this->unit->id,
        ]);

        $this->put(route('inventory.products.update', $product), [
            'name'          => 'ورق مقوّى',
            'category_id'   => $this->category->id,
            'unit_id'       => $this->unit->id,
            'branch_id'     => $this->branch->id,
            'cost_price'    => 12.00,
            'selling_price' => 20.00,
            'is_active'     => true,
        ])->assertRedirect(route('inventory.products.index'));

        $this->assertDatabaseHas('products', [
            'id'   => $product->id,
            'name' => 'ورق مقوّى',
        ]);
    });

    // ── TOGGLE STATUS ──────────────────────────────────────────────

    it('toggles product status from active to inactive', function () {
        $product = Product::factory()->create([
            'branch_id'   => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id'     => $this->unit->id,
            'is_active'   => true,
        ]);

        $this->patch(route('inventory.products.toggle-status', $product))
            ->assertRedirect(route('inventory.products.index'));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => false]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('soft-deletes a product not referenced by invoices', function () {
        $product = Product::factory()->create([
            'branch_id'   => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id'     => $this->unit->id,
        ]);

        $this->delete(route('inventory.products.destroy', $product))
            ->assertRedirect(route('inventory.products.index'));

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('prevents accountant from creating products', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole('accountant');
        $this->actingAs($accountant);

        $this->post(route('inventory.products.store'), [
            'name'          => 'منتج جديد',
            'category_id'   => $this->category->id,
            'unit_id'       => $this->unit->id,
            'cost_price'    => 5.00,
            'selling_price' => 10.00,
        ])->assertForbidden();
    });

    it('prevents branch-admin from managing products of another branch', function () {
        $otherBranch  = Branch::factory()->create();
        $otherProduct = Product::factory()->create([
            'branch_id'   => $otherBranch->id,
            'category_id' => ProductCategory::factory()->create(),
            'unit_id'     => $this->unit->id,
        ]);

        $this->delete(route('inventory.products.destroy', $otherProduct))
            ->assertForbidden();
    });

    // ── LOW-STOCK BANNER ───────────────────────────────────────────

    it('returns correct low stock count in index props', function () {
        Product::factory()->create([
            'branch_id'       => $this->branch->id,
            'category_id'     => $this->category->id,
            'unit_id'         => $this->unit->id,
            'current_stock'   => 2,
            'min_stock_level' => 5,
            'is_active'       => true,
        ]);

        $this->get(route('inventory.products.index'))
            ->assertInertia(fn ($page) => $page
                ->where('lowStockCount', 1)
            );
    });
});
