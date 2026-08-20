<?php

use App\Enums\Roles;
use App\Exports\CatalogueExport;
use App\Models\Branch;
use App\Models\CatalogCategory;
use App\Models\CatalogPrice;
use App\Models\CatalogSubcategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/** A tiny UTF-8 CSV the Excel reader can pick up (the BOM keeps Arabic detection reliable). */
function branchCatalogueCsv(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'branch-catalogue').'.csv';
    file_put_contents($path, "\xEF\xBB\xBF".$content);

    return new UploadedFile($path, 'catalogue.csv', 'text/csv', null, true);
}

/**
 * تاسك 47 — دليل الأسعار ببيانات مستقلة لكل فرع.
 *
 * The category → subcategory tree stays shared; only the price rows carry a
 * branch. A branch row wins over the general one bearing the same name, and a
 * branch that never re-priced an item keeps reading the general figure.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->branchAdmin = User::factory()->create();
    $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
    // A branch admin's branch is the one they own, not their branch_id column.
    $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);

    $this->otherBranch = Branch::factory()->create();

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

    $this->category = CatalogCategory::create(['name_ar' => 'طباعة', 'is_active' => true]);
    $this->subcategory = CatalogSubcategory::create([
        'name_ar' => 'كروت',
        'category_id' => $this->category->id,
        'is_active' => true,
    ]);

    $this->makePrice = fn (array $attributes) => CatalogPrice::create([
        'subcategory_id' => $this->subcategory->id,
        'min_price' => 10,
        'max_price' => 20,
        'base_price' => 15,
        'is_active' => true,
        ...$attributes,
    ]);
});

describe('branch price resolution', function () {
    it('shows a branch its own price instead of the general one', function () {
        ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => null, 'base_price' => 15]);
        ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => $this->branch->id, 'base_price' => 25]);

        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->get(route('services.price-list'))
            ->assertInertia(fn ($page) => $page
                // The override collapses the pair into a single row.
                ->has('categories.0.subcategories.0.prices', 1)
                ->where('categories.0.subcategories.0.prices.0.basePrice', 25)
                ->where('categories.0.subcategories.0.prices.0.isBranchPrice', true)
            );
    });

    it('falls back to the general price for a branch that never re-priced it', function () {
        ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => null, 'base_price' => 15]);
        ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => $this->otherBranch->id, 'base_price' => 25]);

        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->get(route('services.price-list'))
            ->assertInertia(fn ($page) => $page
                ->has('categories.0.subcategories.0.prices', 1)
                ->where('categories.0.subcategories.0.prices.0.basePrice', 15)
                ->where('categories.0.subcategories.0.prices.0.isBranchPrice', false)
            );
    });

    it('keeps a branch-only item out of the other branches lists', function () {
        ($this->makePrice)(['name' => 'بند خاص بالفرع', 'branch_id' => $this->otherBranch->id]);

        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->get(route('services.price-list'))
            ->assertInertia(fn ($page) => $page->where('categories.0.subcategories.0.prices', []));
    });

    it('lets the super admin read any branch list and defaults them to the general one', function () {
        ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => null, 'base_price' => 15]);
        ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => $this->branch->id, 'base_price' => 25]);

        $this->actingAs($this->superAdmin)
            ->get(route('services.price-list'))
            ->assertInertia(fn ($page) => $page
                ->where('selectedBranchId', null)
                ->has('branches')
                ->where('categories.0.subcategories.0.prices.0.basePrice', 15)
            );

        $this->actingAs($this->superAdmin)
            ->get(route('services.price-list', ['branch' => $this->branch->id]))
            ->assertInertia(fn ($page) => $page
                ->where('selectedBranchId', $this->branch->id)
                ->where('categories.0.subcategories.0.prices.0.basePrice', 25)
            );
    });

    it('serves the public catalogue the general list until a branch is picked', function () {
        ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => null, 'base_price' => 15]);
        ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => $this->branch->id, 'base_price' => 25]);

        $this->get(route('catalogue.index'))
            ->assertInertia(fn ($page) => $page->where('categories.0.subcategories.0.prices.0.basePrice', 15));

        $this->get(route('catalogue.index', ['branch' => $this->branch->id]))
            ->assertInertia(fn ($page) => $page->where('categories.0.subcategories.0.prices.0.basePrice', 25));
    });

    it('ignores an unknown branch on the public catalogue rather than guessing', function () {
        ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => null, 'base_price' => 15]);

        $this->get(route('catalogue.index', ['branch' => 9999]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('selectedBranchId', null));
    });
});

describe('branch admin pricing', function () {
    it('pins a new price to the branch admin own branch whatever they post', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('admin.catalogue.prices.store'), [
                'subcategory_id' => $this->subcategory->id,
                // A hand-rolled request trying to price another branch.
                'branch_id' => $this->otherBranch->id,
                'name' => 'كرت شخصي',
                'min_price' => 10,
                'max_price' => 30,
                'base_price' => 20,
            ])->assertRedirect();

        expect(CatalogPrice::firstWhere('name', 'كرت شخصي')->branch_id)->toBe($this->branch->id);
    });

    it('accepts a branch price alongside the general one carrying the same name', function () {
        ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => null]);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.catalogue.prices.store'), [
                'subcategory_id' => $this->subcategory->id,
                'name' => 'كرت شخصي',
                'min_price' => 10,
                'max_price' => 30,
                'base_price' => 20,
            ])->assertSessionHasNoErrors();

        expect(CatalogPrice::where('name', 'كرت شخصي')->count())->toBe(2);
    });

    it('rejects a second price with the same name inside the same branch', function () {
        ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => $this->branch->id]);

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.catalogue.prices.store'), [
                'subcategory_id' => $this->subcategory->id,
                'name' => 'كرت شخصي',
                'min_price' => 10,
                'max_price' => 30,
                'base_price' => 20,
            ])->assertSessionHasErrors('name');
    });

    it('forbids the branch admin from touching another branch price', function () {
        $foreign = ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => $this->otherBranch->id]);

        $this->actingAs($this->branchAdmin)
            ->put(route('admin.catalogue.prices.update', $foreign), [
                'name' => 'كرت شخصي',
                'min_price' => 1,
                'max_price' => 2,
                'base_price' => 1,
            ])->assertForbidden();

        $this->actingAs($this->branchAdmin)
            ->delete(route('admin.catalogue.prices.destroy', $foreign))
            ->assertForbidden();
    });

    it('forbids the branch admin from editing the shared general price', function () {
        $general = ($this->makePrice)(['name' => 'كرت شخصي', 'branch_id' => null]);

        $this->actingAs($this->branchAdmin)
            ->put(route('admin.catalogue.prices.update', $general), [
                'name' => 'كرت شخصي',
                'min_price' => 1,
                'max_price' => 2,
                'base_price' => 1,
            ])->assertForbidden();
    });

    it('lists the branch own rows and the general ones, never another branch', function () {
        ($this->makePrice)(['name' => 'عام', 'branch_id' => null]);
        ($this->makePrice)(['name' => 'خاص بفرعي', 'branch_id' => $this->branch->id]);
        ($this->makePrice)(['name' => 'فرع آخر', 'branch_id' => $this->otherBranch->id]);

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.catalogue.prices.index', $this->subcategory))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/catalogue/prices/index')
                ->has('prices.data', 2)
                ->where('branches', null)
                ->where('ownBranchId', $this->branch->id)
            );
    });

});

describe('branch admin tree', function () {
    it('lets the branch admin add a category pinned to their own branch', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('admin.catalogue.categories.store'), [
                'name_ar' => 'فئة الفرع',
                // A hand-rolled request trying to author for another branch.
                'branch_id' => $this->otherBranch->id,
            ])->assertSessionHasNoErrors();

        expect(CatalogCategory::firstWhere('name_ar', 'فئة الفرع')->branch_id)->toBe($this->branch->id);
    });

    it('lets the branch admin hang a subcategory under a general category', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('admin.catalogue.subcategories.store'), [
                'category_id' => $this->category->id,
                'name_ar' => 'خدمة الفرع',
            ])->assertSessionHasNoErrors();

        $sub = CatalogSubcategory::firstWhere('name_ar', 'خدمة الفرع');

        expect($sub->branch_id)->toBe($this->branch->id)
            ->and($sub->category_id)->toBe($this->category->id);
    });

    it('refuses a branch name that repeats one the branch already inherits', function () {
        // The tree is additive, so the two would sit side by side in the branch
        // list with nothing to tell them apart.
        $this->actingAs($this->branchAdmin)
            ->post(route('admin.catalogue.categories.store'), ['name_ar' => 'طباعة'])
            ->assertSessionHasErrors('name_ar');
    });

    it('forbids the branch admin from editing or deleting a general row', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('admin.catalogue.categories.update', $this->category), ['name_ar' => 'اسم جديد'])
            ->assertForbidden();

        $this->actingAs($this->branchAdmin)
            ->delete(route('admin.catalogue.subcategories.destroy', $this->subcategory))
            ->assertForbidden();
    });

    it('forbids the branch admin from touching another branch row', function () {
        $foreign = CatalogCategory::create(['name_ar' => 'فئة فرع آخر', 'branch_id' => $this->otherBranch->id]);

        $this->actingAs($this->branchAdmin)
            ->delete(route('admin.catalogue.categories.destroy', $foreign))
            ->assertForbidden();
    });

    it('lists the branch own rows and the general ones, never another branch', function () {
        CatalogCategory::create(['name_ar' => 'فئة فرعي', 'branch_id' => $this->branch->id]);
        CatalogCategory::create(['name_ar' => 'فئة فرع آخر', 'branch_id' => $this->otherBranch->id]);

        $this->actingAs($this->branchAdmin)
            ->get(route('admin.catalogue.categories.index'))
            ->assertOk()
            // The general 'طباعة' from the setup plus the branch's own row.
            ->assertInertia(fn ($page) => $page
                ->has('categories.data', 2)
                ->where('branches', null)
                ->where('ownBranchId', $this->branch->id)
            );
    });

    it('shows a branch its own tree additions and hides another branch additions', function () {
        $ownCategory = CatalogCategory::create(['name_ar' => 'حصري لفرعي', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $ownSub = CatalogSubcategory::create([
            'name_ar' => 'خدمة حصرية',
            'category_id' => $ownCategory->id,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        CatalogPrice::create([
            'subcategory_id' => $ownSub->id,
            'branch_id' => $this->branch->id,
            'name' => 'بند',
            'min_price' => 1,
            'max_price' => 2,
            'base_price' => 1.5,
            'is_active' => true,
        ]);

        CatalogCategory::create(['name_ar' => 'حصري لفرع آخر', 'branch_id' => $this->otherBranch->id, 'is_active' => true]);

        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $names = collect(
            $this->actingAs($employee)
                ->get(route('services.price-list'))
                ->viewData('page')['props']['categories']
        )->pluck('nameAr');

        // The general category from the setup plus the branch's own — and never
        // the one another branch added. (Empty categories still show: the tree
        // has always listed them, prices or not.)
        expect($names)->toContain('طباعة', 'حصري لفرعي')
            ->not->toContain('حصري لفرع آخر');
    });
});

describe('super admin pricing', function () {
    it('authors a general price when no branch is chosen', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.catalogue.prices.store'), [
                'subcategory_id' => $this->subcategory->id,
                'name' => 'كرت شخصي',
                'min_price' => 10,
                'max_price' => 30,
                'base_price' => 20,
            ])->assertSessionHasNoErrors();

        expect(CatalogPrice::firstWhere('name', 'كرت شخصي')->branch_id)->toBeNull();
    });

    it('authors a price for any branch and sees every row', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.catalogue.prices.store'), [
                'subcategory_id' => $this->subcategory->id,
                'branch_id' => $this->otherBranch->id,
                'name' => 'كرت شخصي',
                'min_price' => 10,
                'max_price' => 30,
                'base_price' => 20,
            ])->assertSessionHasNoErrors();

        expect(CatalogPrice::firstWhere('name', 'كرت شخصي')->branch_id)->toBe($this->otherBranch->id);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.catalogue.prices.index', $this->subcategory))
            ->assertInertia(fn ($page) => $page->has('prices.data', 1)->has('branches'));
    });

    it('narrows the list to one branch through the branch filter', function () {
        ($this->makePrice)(['name' => 'عام', 'branch_id' => null]);
        ($this->makePrice)(['name' => 'فرع', 'branch_id' => $this->branch->id]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.catalogue.prices.index', ['subcategory' => $this->subcategory, 'branch' => 'general']))
            ->assertInertia(fn ($page) => $page->has('prices.data', 1)->where('prices.data.0.name', 'عام'));

        $this->actingAs($this->superAdmin)
            ->get(route('admin.catalogue.prices.index', ['subcategory' => $this->subcategory, 'branch' => $this->branch->id]))
            ->assertInertia(fn ($page) => $page->has('prices.data', 1)->where('prices.data.0.name', 'فرع'));
    });
});

describe('branch-scoped full catalogue sheet', function () {
    it('imports a branch sheet into the branch, reusing the shared categories', function () {
        $csv = "category,subcategory,price_name,min,max,base,active\n"
            // Names that already exist generally: they must be reused, not forked.
            .'طباعة,كروت,كرت شخصي,10,25,20,1'."\n"
            .'دعاية,بنرات,بنر متر,50,90,70,1'."\n";

        $this->actingAs($this->branchAdmin)
            ->post(route('admin.catalogue.import'), ['file' => branchCatalogueCsv($csv)])
            ->assertOk();

        // The shared category and subcategory were reused as they stand.
        expect(CatalogCategory::where('name_ar', 'طباعة')->count())->toBe(1)
            ->and(CatalogCategory::firstWhere('name_ar', 'طباعة')->branch_id)->toBeNull()
            ->and(CatalogSubcategory::where('name_ar', 'كروت')->count())->toBe(1);

        // What the branch actually authored is its own: the new tree rows and
        // every price the sheet carried.
        expect(CatalogCategory::firstWhere('name_ar', 'دعاية')->branch_id)->toBe($this->branch->id)
            ->and(CatalogSubcategory::firstWhere('name_ar', 'بنرات')->branch_id)->toBe($this->branch->id)
            ->and(CatalogPrice::firstWhere('name', 'كرت شخصي')->branch_id)->toBe($this->branch->id);
    });

    it('keeps the super admin sheet on the shared catalogue when no branch is filtered', function () {
        // Two data rows on purpose: PhpSpreadsheet delimiter detection is
        // unreliable on a CSV with a single one.
        $csv = "category,subcategory,price_name,min,max,base,active\n"
            .'دعاية,بنرات,بنر متر,50,90,70,1'."\n"
            .'دعاية,بنرات,بنر كبير,80,120,100,1'."\n";

        $this->actingAs($this->superAdmin)
            ->post(route('admin.catalogue.import'), ['file' => branchCatalogueCsv($csv)])
            ->assertOk();

        expect(CatalogCategory::firstWhere('name_ar', 'دعاية')->branch_id)->toBeNull()
            ->and(CatalogPrice::firstWhere('name', 'بنر متر')->branch_id)->toBeNull();
    });

    it('lets the super admin import straight into one branch through the filter', function () {
        // Two data rows on purpose: PhpSpreadsheet delimiter detection is
        // unreliable on a CSV with a single one.
        $csv = "category,subcategory,price_name,min,max,base,active\n"
            .'دعاية,بنرات,بنر متر,50,90,70,1'."\n"
            .'دعاية,بنرات,بنر كبير,80,120,100,1'."\n";

        $this->actingAs($this->superAdmin)
            ->post(route('admin.catalogue.import'), [
                'file' => branchCatalogueCsv($csv),
                'branch' => (string) $this->otherBranch->id,
            ])->assertOk();

        expect(CatalogCategory::firstWhere('name_ar', 'دعاية')->branch_id)->toBe($this->otherBranch->id);
    });

    it('exports only the rows the owner wrote', function () {
        // Shared: a category and subcategory with a general price.
        CatalogPrice::create([
            'subcategory_id' => $this->subcategory->id,
            'branch_id' => null,
            'name' => 'سعر عام',
            'min_price' => 1,
            'max_price' => 2,
            'base_price' => 1.5,
            'is_active' => true,
        ]);

        // The branch: an override under the shared subcategory, plus a category
        // of its own.
        CatalogPrice::create([
            'subcategory_id' => $this->subcategory->id,
            'branch_id' => $this->branch->id,
            'name' => 'سعر الفرع',
            'min_price' => 3,
            'max_price' => 4,
            'base_price' => 3.5,
            'is_active' => true,
        ]);
        CatalogCategory::create(['name_ar' => 'حصري لفرعي', 'branch_id' => $this->branch->id]);

        $rows = (new CatalogueExport($this->branch->id))->collection();
        $names = $rows->map(fn ($row) => $row[2]);

        // The branch price is there under its (shared) parents; the general
        // price is not — it is not the branch's to re-import.
        expect($names)->toContain('سعر الفرع')
            ->not->toContain('سعر عام');

        // The branch's own empty category still travels, so the sheet carries
        // the structure the branch built.
        expect($rows->map(fn ($row) => $row[0]))->toContain('حصري لفرعي');

        // And the shared view is the mirror image of that.
        $general = (new CatalogueExport)->collection()->map(fn ($row) => $row[2]);
        expect($general)->toContain('سعر عام')->not->toContain('سعر الفرع');
    });
});
