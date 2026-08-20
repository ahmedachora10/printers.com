<?php

use App\Exports\CatalogPricesExport;
use App\Exports\CatalogueExport;
use App\Models\Branch;
use App\Models\CatalogCategory;
use App\Models\CatalogPrice;
use App\Models\CatalogSubcategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * The two-step import: preview (reads, reports, writes nothing) then commit.
 *
 * The regression these tests pin down: a sheet with the **Arabic** headings the
 * export writes used to import zero rows and still report success. The old
 * tests only ever fed English headings, which slug to themselves — so the whole
 * feature was broken for every real user while the suite stayed green.
 */
function importCsv(string $content, string $name = 'catalogue.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
    // BOM: PhpSpreadsheet's encoding detection is flaky on small Arabic CSVs.
    file_put_contents($path, "\xEF\xBB\xBF".$content);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

/** A sheet built from the export's own headings, so the two cannot drift apart. */
function exportedCatalogueCsv(array $rows): UploadedFile
{
    $lines = [implode(',', (new CatalogueExport)->headings())];

    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }

    return importCsv(implode("\n", $lines)."\n");
}

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->addRole('super-admin');
    $this->actingAs($this->superAdmin);
});

describe('full catalogue import', function () {
    it('imports a sheet carrying the very headings the export writes', function () {
        $file = exportedCatalogueCsv([
            ['طباعة', 'كروت', 'كرت شخصي', '10.00', '25.00', '15.00', 1],
            ['طباعة', 'كروت', 'كرت فاخر', '30.00', '60.00', '45.00', 1],
        ]);

        $response = $this->post(route('admin.catalogue.import'), ['file' => $file])->assertOk();

        expect($response->json('totalRows'))->toBe(2);
        $this->assertDatabaseHas('catalog_categories', ['name_ar' => 'طباعة']);
        $this->assertDatabaseHas('catalog_subcategories', ['name_ar' => 'كروت']);
        $this->assertDatabaseHas('catalog_prices', ['name' => 'كرت شخصي', 'base_price' => 15]);
        $this->assertDatabaseHas('catalog_prices', ['name' => 'كرت فاخر', 'base_price' => 45]);
    });

    it('previews without writing a single row, and reports what it would do', function () {
        Storage::fake('local');

        $file = exportedCatalogueCsv([
            ['طباعة', 'كروت', 'كرت شخصي', '10.00', '25.00', '15.00', 1],
            ['طباعة', 'كروت', 'كرت فاخر', '30.00', '60.00', '45.00', 1],
        ]);

        $response = $this->post(route('admin.catalogue.import.preview'), ['file' => $file])->assertOk();

        expect($response->json('dryRun'))->toBeTrue()
            ->and($response->json('token'))->not->toBeNull()
            ->and($response->json('totalRows'))->toBe(2);

        $counts = collect($response->json('summary'))->pluck('value', 'key');
        expect($counts['categoriesCreated'])->toBe(1)
            ->and($counts['subcategoriesCreated'])->toBe(1)
            ->and($counts['pricesCreated'])->toBe(2)
            ->and($counts['skipped'])->toBe(0);

        // The whole point: nothing was written.
        expect(CatalogCategory::count())->toBe(0)
            ->and(CatalogSubcategory::count())->toBe(0)
            ->and(CatalogPrice::count())->toBe(0);
    });

    it('commits the parked file by token and cleans it up', function () {
        Storage::fake('local');

        $file = exportedCatalogueCsv([
            ['طباعة', 'كروت', 'كرت شخصي', '10.00', '25.00', '15.00', 1],
            ['طباعة', 'كروت', 'كرت فاخر', '30.00', '60.00', '45.00', 1],
        ]);

        $token = $this->post(route('admin.catalogue.import.preview'), ['file' => $file])->json('token');

        Storage::disk('local')->assertExists('imports/'.$this->superAdmin->id.'/'.$token);

        $response = $this->post(route('admin.catalogue.import'), ['token' => $token])->assertOk();

        expect($response->json('dryRun'))->toBeFalse();
        $this->assertDatabaseHas('catalog_prices', ['name' => 'كرت شخصي', 'base_price' => 15]);
        $this->assertDatabaseHas('catalog_prices', ['name' => 'كرت فاخر', 'base_price' => 45]);

        // A committed sheet does not linger in private storage.
        Storage::disk('local')->assertMissing('imports/'.$this->superAdmin->id.'/'.$token);
    });

    it('rejects a token that is not the caller\'s own', function () {
        Storage::fake('local');

        $file = exportedCatalogueCsv([
            ['طباعة', 'كروت', 'كرت شخصي', '10.00', '25.00', '15.00', 1],
            ['طباعة', 'كروت', 'كرت فاخر', '30.00', '60.00', '45.00', 1],
        ]);

        $token = $this->post(route('admin.catalogue.import.preview'), ['file' => $file])->json('token');

        $otherAdmin = User::factory()->create();
        $otherAdmin->addRole('super-admin');

        $this->actingAs($otherAdmin)
            ->post(route('admin.catalogue.import'), ['token' => $token])
            ->assertInvalid('token');

        expect(CatalogPrice::count())->toBe(0);
    });

    it('imports the good rows and reports the bad ones with their row numbers', function () {
        $file = exportedCatalogueCsv([
            ['طباعة', 'كروت', 'كرت شخصي', '10.00', '25.00', '15.00', 1],
            ['طباعة', 'كروت', 'كرت تالف', 'غير رقمي', '25.00', '15.00', 1],
            ['طباعة', 'كروت', 'كرت فاخر', '30.00', '60.00', '45.00', 1],
        ]);

        $response = $this->post(route('admin.catalogue.import'), ['file' => $file])->assertOk();

        expect($response->json('skipped'))->toHaveCount(1)
            // Row 3 of the sheet: heading row + the first data row before it.
            ->and($response->json('skipped.0.row'))->toBe(3)
            ->and($response->json('skipped.0.reason'))->toBe('قيمة سعر غير رقمية');

        $this->assertDatabaseHas('catalog_prices', ['name' => 'كرت شخصي']);
        $this->assertDatabaseHas('catalog_prices', ['name' => 'كرت فاخر']);
        $this->assertDatabaseMissing('catalog_prices', ['name' => 'كرت تالف']);
    });

    it('reports an update as an update, not a second row', function () {
        $rows = [
            ['طباعة', 'كروت', 'كرت شخصي', '10.00', '25.00', '15.00', 1],
            ['طباعة', 'كروت', 'كرت فاخر', '30.00', '60.00', '45.00', 1],
        ];

        $this->post(route('admin.catalogue.import'), ['file' => exportedCatalogueCsv($rows)])->assertOk();

        $rows[0][5] = '20.00';
        $response = $this->post(route('admin.catalogue.import'), ['file' => exportedCatalogueCsv($rows)])->assertOk();

        $counts = collect($response->json('summary'))->pluck('value', 'key');
        expect($counts['pricesCreated'])->toBe(0)
            ->and($counts['pricesUpdated'])->toBe(2)
            ->and(CatalogPrice::where('name', 'كرت شخصي')->count())->toBe(1);
        $this->assertDatabaseHas('catalog_prices', ['name' => 'كرت شخصي', 'base_price' => 20]);
    });

    it('offers a template sheet whose headings match the export', function () {
        $this->get(route('admin.catalogue.import.template'))
            ->assertOk()
            ->assertDownload('catalogue-template.xlsx');
    });

    it('keeps the import behind the create permission', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');

        $this->actingAs($employee)
            ->post(route('admin.catalogue.import.preview'), ['file' => exportedCatalogueCsv([])])
            ->assertForbidden();
    });
});

describe('subcategory price import', function () {
    beforeEach(function () {
        $this->category = CatalogCategory::create(['name_ar' => 'طباعة']);
        $this->subcategory = CatalogSubcategory::create([
            'category_id' => $this->category->id,
            'name_ar' => 'كروت',
        ]);
    });

    it('imports a price sheet with the export\'s Arabic headings', function () {
        $headings = implode(',', (new CatalogPricesExport($this->subcategory->id))->headings());
        $file = importCsv($headings."\n".'كرت شخصي,10.00,25.00,15.00'."\n".'كرت فاخر,30.00,60.00,45.00'."\n");

        $response = $this->post(route('admin.catalogue.prices.import', $this->subcategory), ['file' => $file])
            ->assertOk();

        expect($response->json('totalRows'))->toBe(2);
        $this->assertDatabaseHas('catalog_prices', [
            'subcategory_id' => $this->subcategory->id,
            'name' => 'كرت شخصي',
            'base_price' => 15,
        ]);
    });

    it('previews a price sheet without writing', function () {
        Storage::fake('local');

        $headings = implode(',', (new CatalogPricesExport($this->subcategory->id))->headings());
        $file = importCsv($headings."\n".'كرت شخصي,10.00,25.00,15.00'."\n".'كرت فاخر,30.00,60.00,45.00'."\n");

        $response = $this->post(route('admin.catalogue.prices.import.preview', $this->subcategory), ['file' => $file])
            ->assertOk();

        expect($response->json('dryRun'))->toBeTrue()
            ->and(CatalogPrice::count())->toBe(0);
    });
});

describe('destination of the sheet', function () {
    it('pins a branch admin\'s sheet to their own branch', function () {
        $branchAdmin = User::factory()->create();
        $branchAdmin->addRole('branch-admin');
        // A branch admin's branch is the one they own, not their branch_id column.
        $branch = Branch::factory()->create(['owner_id' => $branchAdmin->id]);

        $file = exportedCatalogueCsv([
            ['دعاية', 'بنرات', 'بنر متر', '50.00', '90.00', '70.00', 1],
            ['دعاية', 'بنرات', 'بنر كبير', '80.00', '120.00', '100.00', 1],
        ]);

        // Even asked to write the shared catalogue, the server pins them.
        $this->actingAs($branchAdmin)
            ->post(route('admin.catalogue.import'), ['file' => $file, 'branch' => 'general'])
            ->assertOk();

        expect(CatalogCategory::firstWhere('name_ar', 'دعاية')->branch_id)->toBe($branch->id)
            ->and(CatalogPrice::firstWhere('name', 'بنر متر')->branch_id)->toBe($branch->id);
    });

    it('tells a branch admin which branch the sheet will land on', function () {
        $branchAdmin = User::factory()->create();
        $branchAdmin->addRole('branch-admin');
        Branch::factory()->create(['name' => 'فرع العليا', 'owner_id' => $branchAdmin->id]);

        $this->actingAs($branchAdmin)
            ->get(route('admin.catalogue.categories.index'))
            ->assertInertia(fn ($page) => $page
                ->where('ownBranchName', 'فرع العليا')
                ->where('branches', null));
    });
});
