<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\CatalogCategory;
use App\Models\CatalogPrice;
use App\Models\CatalogSubcategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Service price list', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create();

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
    });

    it('lets an employee open the price list', function () {
        $this->actingAs($this->employee)
            ->get(route('services.price-list'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('services/price-list')->has('categories'));
    });

    it('is open to accountants, branch admins and super admins too', function (string $role) {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->addRole($role);

        $this->actingAs($user)
            ->get(route('services.price-list'))
            ->assertOk();
    })->with([
        Roles::ACCOUNTANT->value,
        Roles::BRANCH_ADMIN->value,
        Roles::SUPER_ADMIN->value,
    ]);

    it('forbids an agent', function () {
        $agent = User::factory()->create(['branch_id' => $this->branch->id]);
        $agent->addRole(Roles::AGENT->value);

        $this->actingAs($agent)
            ->get(route('services.price-list'))
            ->assertForbidden();
    });

    it('requires authentication', function () {
        $this->get(route('services.price-list'))->assertRedirect(route('login'));
    });

    it('shows only active categories, subcategories and prices', function () {
        $category = CatalogCategory::create(['name_ar' => 'طباعة', 'is_active' => true]);
        $sub = CatalogSubcategory::create(['name_ar' => 'كروت', 'category_id' => $category->id, 'is_active' => true]);
        CatalogPrice::create([
            'subcategory_id' => $sub->id,
            'name' => 'كرت شخصي',
            'min_price' => 10,
            'max_price' => 25,
            'base_price' => 15,
            'is_active' => true,
        ]);
        CatalogPrice::create([
            'subcategory_id' => $sub->id,
            'name' => 'سعر موقوف',
            'min_price' => 5,
            'max_price' => 5,
            'base_price' => 5,
            'is_active' => false,
        ]);

        // A hidden subcategory under the same (active) category, and a wholly
        // hidden category — neither may reach the page.
        $hiddenSub = CatalogSubcategory::create(['name_ar' => 'مخفي', 'category_id' => $category->id, 'is_active' => false]);
        CatalogPrice::create([
            'subcategory_id' => $hiddenSub->id,
            'name' => 'بند مخفي',
            'min_price' => 1,
            'max_price' => 2,
            'base_price' => 1.5,
            'is_active' => true,
        ]);

        CatalogCategory::create(['name_ar' => 'فئة موقوفة', 'is_active' => false]);

        $this->actingAs($this->employee)
            ->get(route('services.price-list'))
            ->assertInertia(fn ($page) => $page
                ->component('services/price-list')
                ->has('categories', 1)
                ->where('categories.0.nameAr', 'طباعة')
                ->has('categories.0.subcategories', 1)
                ->where('categories.0.subcategories.0.nameAr', 'كروت')
                ->has('categories.0.subcategories.0.prices', 1)
                ->where('categories.0.subcategories.0.prices.0.name', 'كرت شخصي')
                ->where('categories.0.subcategories.0.prices.0.basePrice', 15)
                ->where('categories.0.subcategories.0.prices.0.minPrice', 10)
                ->where('categories.0.subcategories.0.prices.0.maxPrice', 25)
            );
    });
});
