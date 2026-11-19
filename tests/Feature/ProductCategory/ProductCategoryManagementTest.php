<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('ProductCategory Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole('branch-admin');
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);
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
        // تاسك 237: فئة مدير الفرع لفرعه.
        expect(ProductCategory::firstWhere('name', 'ورق طباعة')->branchIds())->toBe([$this->branch->id]);
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
        $category = ProductCategory::factory()->forBranches($this->branch->id)->create();

        $this->put(route('product-categories.update', $category), ['name' => 'أحبار وأصباغ'])
            ->assertRedirect(route('product-categories.index'));

        $this->assertDatabaseHas('product_categories', [
            'id'   => $category->id,
            'name' => 'أحبار وأصباغ',
        ]);
    });

    // ── TOGGLE STATUS ──────────────────────────────────────────────

    it('toggles product category status from active to inactive', function () {
        $category = ProductCategory::factory()->forBranches($this->branch->id)->create(['is_active' => true]);

        $this->patch(route('product-categories.toggle-status', $category))
            ->assertRedirect(route('product-categories.index'));

        $this->assertDatabaseHas('product_categories', ['id' => $category->id, 'is_active' => false]);
    });

    it('toggles product category status from inactive to active', function () {
        $category = ProductCategory::factory()->forBranches($this->branch->id)->create(['is_active' => false]);

        $this->patch(route('product-categories.toggle-status', $category))
            ->assertRedirect(route('product-categories.index'));

        $this->assertDatabaseHas('product_categories', ['id' => $category->id, 'is_active' => true]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('deletes a product category not referenced by any product', function () {
        $category = ProductCategory::factory()->forBranches($this->branch->id)->create();

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

    // ── تاسك 237: فئات لفرعٍ أو عدّة فروع ──────────────────────────

    describe('branch scope', function () {
        beforeEach(function () {
            $this->otherBranch = Branch::factory()->create();
            $this->thirdBranch = Branch::factory()->create();
            $this->superAdmin = User::factory()->create();
            $this->superAdmin->addRole('super-admin');
        });

        it('lets the super-admin scope a category to two branches at once', function () {
            $this->actingAs($this->superAdmin)
                ->post(route('product-categories.store'), ['name' => 'دروع', 'branch_ids' => [$this->branch->id, $this->otherBranch->id]])
                ->assertSessionHasNoErrors();

            $category = ProductCategory::firstWhere('name', 'دروع');
            expect(collect($category->branchIds())->sort()->values()->all())->toBe([$this->branch->id, $this->otherBranch->id]);

            // يراها الفرع الأول في خيارات المنتج، ولا يراها فرعٌ ثالث ولا يقبل الخادم معرّفها منه.
            $this->actingAs($this->branchAdmin)->get(route('inventory.products.index'))
                ->assertInertia(fn ($page) => $page->where('categories', fn ($rows) => collect($rows)->pluck('id')->contains($category->id)));

            $thirdAdmin = User::factory()->create();
            $thirdAdmin->addRole('branch-admin');
            $this->thirdBranch->update(['owner_id' => $thirdAdmin->id]);
            $thirdAdmin->update(['branch_id' => $this->thirdBranch->id]);

            $this->actingAs($thirdAdmin)->get(route('inventory.products.index'))
                ->assertInertia(fn ($page) => $page->where('categories', fn ($rows) => ! collect($rows)->pluck('id')->contains($category->id)));
            $this->post(route('inventory.products.store'), [
                'name' => 'درع', 'category_id' => $category->id, 'unit_id' => \App\Models\ProductUnit::factory()->create()->id,
                'cost_price' => 1, 'selling_price' => 2,
            ])->assertSessionHasErrors('category_id');
        });

        it('refuses narrowing a category whose products sit in a branch outside the scope', function () {
            $category = ProductCategory::factory()->create(['name' => 'قرطاسية']);
            Product::factory()->create(['category_id' => $category->id, 'branch_id' => $this->otherBranch->id]);

            $this->actingAs($this->superAdmin)
                ->put(route('product-categories.update', $category), ['name' => 'قرطاسية', 'branch_ids' => [$this->branch->id]])
                ->assertSessionHasErrors(['branch_ids' => 'لها منتجات في: '.$this->otherBranch->name.' — أضِفها إلى النطاق أو اتركها عامة.']);

            $this->put(route('product-categories.update', $category), ['name' => 'قرطاسية', 'branch_ids' => [$this->branch->id, $this->otherBranch->id]])
                ->assertSessionHasNoErrors();
        });

        it('lets a branch admin edit only a category of their own branch', function () {
            $global = ProductCategory::factory()->create();
            $shared = ProductCategory::factory()->forBranches($this->branch->id, $this->otherBranch->id)->create();
            $mine = ProductCategory::factory()->forBranches($this->branch->id)->create();

            $this->put(route('product-categories.update', $global), ['name' => 'x'])->assertForbidden();
            $this->put(route('product-categories.update', $shared), ['name' => 'y'])->assertForbidden();
            $this->patch(route('product-categories.toggle-status', $shared))->assertForbidden();
            $this->put(route('product-categories.update', $mine), ['name' => 'z', 'branch_ids' => [$this->otherBranch->id]])
                ->assertSessionHasNoErrors();

            // branch_ids من مدير الفرع تُتجاهل.
            expect($mine->fresh()->branchIds())->toBe([$this->branch->id]);
        });

        it('keeps a name unique only where two scopes meet', function () {
            ProductCategory::factory()->forBranches($this->otherBranch->id)->create(['name' => 'أحبار']);

            // فرعٌ آخر منفصل: مسموح.
            $this->post(route('product-categories.store'), ['name' => 'أحبار'])->assertSessionHasNoErrors();

            // عامةٌ بالاسم نفسه تصادم الاثنين.
            $this->actingAs($this->superAdmin)
                ->post(route('product-categories.store'), ['name' => 'أحبار', 'branch_ids' => []])
                ->assertSessionHasErrors('name');
        });
    });
});
