<?php

use App\Exports\CatalogueExport;
use App\Models\CatalogCategory;
use App\Models\CatalogPrice;
use App\Models\CatalogSubcategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

function makeCatalogueCsv(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'catalogue').'.csv';
    // Prepend a UTF-8 BOM so PhpSpreadsheet reliably detects the encoding for
    // small Arabic CSVs (auto-detection is flaky on tiny files).
    file_put_contents($path, "\xEF\xBB\xBF".$content);

    return new UploadedFile($path, 'catalogue.csv', 'text/csv', null, true);
}

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
        $this->seed(RolesAndPermissionsSeeder::class);

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

describe('Full catalogue export/import (M20)', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole('super-admin');
        $this->actingAs($this->superAdmin);
    });

    it('downloads the full catalogue export', function () {
        Excel::fake();

        $this->get(route('admin.catalogue.export'))->assertOk();

        Excel::assertDownloaded('catalogue-'.now()->format('Y-m-d').'.xlsx', function (CatalogueExport $export) {
            return $export instanceof CatalogueExport;
        });
    });

    it('prevents non-super-admin from exporting', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->get(route('admin.catalogue.export'))->assertForbidden();
    });

    it('imports a flat sheet creating the full tree (upsert, no deletes)', function () {
        $csv = "category,subcategory,price_name,min,max,base,active\n"
            ."طباعة,كروت,كرت شخصي,10,25,15,1\n"
            ."طباعة,كروت,كرت فاخر,30,60,45,1\n"
            ."طباعة,بروشور,,,,,\n"
            ."تصوير,,,,,,\n";

        $this->post(route('admin.catalogue.import'), ['file' => makeCatalogueCsv($csv)])
            ->assertOk();

        $this->assertDatabaseHas('catalog_categories', ['name_ar' => 'طباعة']);
        $this->assertDatabaseHas('catalog_categories', ['name_ar' => 'تصوير']);
        $this->assertDatabaseHas('catalog_subcategories', ['name_ar' => 'كروت']);
        $this->assertDatabaseHas('catalog_subcategories', ['name_ar' => 'بروشور']);
        $this->assertDatabaseHas('catalog_prices', ['name' => 'كرت شخصي', 'base_price' => 15]);
        $this->assertDatabaseHas('catalog_prices', ['name' => 'كرت فاخر']);
    });

    it('updates existing rows on re-import without duplicating', function () {
        $csvV1 = "category,subcategory,price_name,min,max,base,active\n"
            ."طباعة,كروت,كرت شخصي,10,25,15,1\n"
            ."طباعة,كروت,كرت فاخر,30,60,45,1\n";
        $this->post(route('admin.catalogue.import'), ['file' => makeCatalogueCsv($csvV1)])->assertOk();

        expect(CatalogCategory::where('name_ar', 'طباعة')->count())->toBe(1)
            ->and(CatalogPrice::where('name', 'كرت شخصي')->value('base_price'))->toEqual('15.00');

        // Re-import the same tree with an updated price for "كرت شخصي".
        $csvV2 = "category,subcategory,price_name,min,max,base,active\n"
            ."طباعة,كروت,كرت شخصي,12,30,20,1\n"
            ."طباعة,كروت,كرت فاخر,30,60,45,1\n";
        $this->post(route('admin.catalogue.import'), ['file' => makeCatalogueCsv($csvV2)])->assertOk();

        // Upsert, not insert: counts stay the same, the price is updated.
        expect(CatalogCategory::where('name_ar', 'طباعة')->count())->toBe(1);
        expect(CatalogSubcategory::where('name_ar', 'كروت')->count())->toBe(1);
        expect(CatalogPrice::where('name', 'كرت شخصي')->count())->toBe(1);
        $this->assertDatabaseHas('catalog_prices', ['name' => 'كرت شخصي', 'base_price' => 20]);
    });
});
