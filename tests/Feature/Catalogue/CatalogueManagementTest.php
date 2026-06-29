<?php

use App\Models\CatalogCategory;
use App\Models\CatalogPrice;
use App\Models\CatalogSubcategory;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Public Catalogue (M19)', function () {
    beforeEach(function () {
        $this->withoutVite();
    });

    it('is accessible without authentication', function () {
        $this->get(route('catalogue.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('catalogue/index'));
    });

    it('only exposes active categories, subcategories and prices', function () {
        $active = CatalogCategory::create(['name_ar' => 'فئة نشطة', 'is_active' => true]);
        $sub = CatalogSubcategory::create(['name_ar' => 'خدمة', 'category_id' => $active->id, 'is_active' => true]);
        CatalogPrice::create(['subcategory_id' => $sub->id, 'name' => 'بند', 'min_price' => 1, 'max_price' => 2, 'base_price' => 1.5, 'is_active' => true]);
        CatalogPrice::create(['subcategory_id' => $sub->id, 'name' => 'مخفي', 'min_price' => 1, 'max_price' => 2, 'base_price' => 1.5, 'is_active' => false]);

        CatalogCategory::create(['name_ar' => 'فئة مخفية', 'is_active' => false]);

        $this->get(route('catalogue.index'))
            ->assertInertia(fn ($page) => $page
                ->component('catalogue/index')
                ->has('categories', 1)
                ->has('categories.0.subcategories.0.prices', 1)
            );
    });
});

describe('Catalogue CRUD (M20)', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole('super-admin');
        $this->actingAs($this->superAdmin);
    });

    it('allows super-admin to view the categories index', function () {
        CatalogCategory::create(['name_ar' => 'طباعة']);

        $this->get(route('admin.catalogue.categories.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/catalogue/categories/index'));
    });

    it('prevents non-super-admin from viewing the categories index', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->get(route('admin.catalogue.categories.index'))->assertForbidden();
    });

    it('creates a category', function () {
        $this->post(route('admin.catalogue.categories.store'), ['name_ar' => 'طباعة', 'is_active' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('catalog_categories', ['name_ar' => 'طباعة']);
    });

    it('creates a subcategory under a category', function () {
        $category = CatalogCategory::create(['name_ar' => 'طباعة']);

        $this->post(route('admin.catalogue.subcategories.store'), [
            'category_id' => $category->id,
            'name_ar' => 'كروت',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('catalog_subcategories', ['name_ar' => 'كروت', 'category_id' => $category->id]);
    });

    it('creates a price and rejects a duplicate name within the same subcategory', function () {
        $category = CatalogCategory::create(['name_ar' => 'طباعة']);
        $sub = CatalogSubcategory::create(['name_ar' => 'كروت', 'category_id' => $category->id]);

        $payload = [
            'subcategory_id' => $sub->id,
            'name' => 'كرت شخصي',
            'min_price' => 10,
            'max_price' => 25,
            'base_price' => 15,
        ];

        $this->post(route('admin.catalogue.prices.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('catalog_prices', ['subcategory_id' => $sub->id, 'name' => 'كرت شخصي']);

        $this->post(route('admin.catalogue.prices.store'), $payload)->assertSessionHasErrors(['name']);
    });

    it('rejects a price where max is below min', function () {
        $category = CatalogCategory::create(['name_ar' => 'طباعة']);
        $sub = CatalogSubcategory::create(['name_ar' => 'كروت', 'category_id' => $category->id]);

        $this->post(route('admin.catalogue.prices.store'), [
            'subcategory_id' => $sub->id,
            'name' => 'خطأ',
            'min_price' => 50,
            'max_price' => 10,
            'base_price' => 30,
        ])->assertSessionHasErrors(['max_price']);
    });

    it('toggles category status', function () {
        $category = CatalogCategory::create(['name_ar' => 'طباعة', 'is_active' => true]);

        $this->patch(route('admin.catalogue.categories.toggle-status', $category))->assertRedirect();

        $this->assertDatabaseHas('catalog_categories', ['id' => $category->id, 'is_active' => false]);
    });

    it('deletes a category and cascades to subcategories and prices', function () {
        $category = CatalogCategory::create(['name_ar' => 'طباعة']);
        $sub = CatalogSubcategory::create(['name_ar' => 'كروت', 'category_id' => $category->id]);
        $price = CatalogPrice::create(['subcategory_id' => $sub->id, 'name' => 'بند', 'min_price' => 1, 'max_price' => 2, 'base_price' => 1.5]);

        $this->delete(route('admin.catalogue.categories.destroy', $category))->assertRedirect();

        $this->assertDatabaseMissing('catalog_categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('catalog_subcategories', ['id' => $sub->id]);
        $this->assertDatabaseMissing('catalog_prices', ['id' => $price->id]);
    });
});
