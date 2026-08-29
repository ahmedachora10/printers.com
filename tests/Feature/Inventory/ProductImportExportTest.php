<?php

use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Exports\ProductCategoriesExport;
use App\Exports\ProductsExport;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * تاسك 72: استيراد/تصدير المنتجات وفئاتها.
 *
 * الانحدار الذي تثبّته هذه الاختبارات هو نفسه الذي عضّ دليل الخدمات: العناوين
 * العربية تُسلَّك إلى لاتينية عند القراءة، فورقةٌ صُدِّرت لتوّها كانت تُستورد صفرَ
 * صفوف وتُبلّغ بالنجاح. لذلك تُبنى كل ورقة هنا من عناوين التصدير نفسها.
 */
function importedSheet(array $headings, array $rows, string $name): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    // strictNullComparison: بدونها تقارن fromArray كل خلية بـnull مقارنةً رخوة،
    // فخليّة قيمتها 0 (عمود «نشط») تُعدّ فارغة وتُحذف من الورقة.
    $spreadsheet->getActiveSheet()->fromArray([$headings, ...$rows], null, 'A1', true);

    $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    // ورقة xlsx حقيقية لا CSV: كشف نوع ملف CSV عربي من سطرٍ واحد متقلّب،
    // فيسقط على قاعدة `mimes` قبل أن يصل الاستيراد أصلاً.
    return new UploadedFile($path, $name, null, null, true);
}

/** @param array<int, array<int, mixed>> $rows */
function exportedProductsSheet(array $rows): UploadedFile
{
    return importedSheet((new ProductsExport)->headings(), $rows, 'products.xlsx');
}

/** @param array<int, array<int, mixed>> $rows */
function exportedCategoriesSheet(array $rows): UploadedFile
{
    return importedSheet((new ProductCategoriesExport)->headings(), $rows, 'product-categories.xlsx');
}

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->addRole(Roles::BRANCH_ADMIN->value);
    $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
    $this->admin->update(['branch_id' => $this->branch->id]);

    $this->category = ProductCategory::factory()->create(['name' => 'قرطاسية']);
    $this->unit = ProductUnit::factory()->create(['name' => 'قطعة']);

    $this->actingAs($this->admin);
});

describe('product categories', function () {
    it('exports every category as a sheet', function () {
        ProductCategory::factory()->create(['name' => 'أحبار', 'is_active' => false]);

        $rows = (new ProductCategoriesExport)->collection();

        expect($rows->pluck(0)->all())->toEqualCanonicalizing(['قرطاسية', 'أحبار']);
    });

    it('imports a sheet carrying the very headings the export writes', function () {
        $response = $this->post(route('product-categories.import'), [
            'file' => exportedCategoriesSheet([['ورق', 1], ['أحبار', 0]]),
        ])->assertOk();

        expect($response->json('totalRows'))->toBe(2);
        $this->assertDatabaseHas('product_categories', ['name' => 'ورق', 'is_active' => true]);
        $this->assertDatabaseHas('product_categories', ['name' => 'أحبار', 'is_active' => false]);
    });

    it('updates a category of the same name instead of duplicating it', function () {
        $this->post(route('product-categories.import'), [
            'file' => exportedCategoriesSheet([['قرطاسية', 0]]),
        ])->assertOk();

        expect(ProductCategory::where('name', 'قرطاسية')->count())->toBe(1)
            ->and($this->category->refresh()->is_active)->toBeFalse();
    });

    it('previews without writing a row', function () {
        $response = $this->post(route('product-categories.import.preview'), [
            'file' => exportedCategoriesSheet([['ورق', 1]]),
        ])->assertOk();

        expect($response->json('dryRun'))->toBeTrue();
        $this->assertDatabaseMissing('product_categories', ['name' => 'ورق']);
    });
});

describe('products', function () {
    it('exports the rows of one branch alone', function () {
        $other = Branch::factory()->create();

        Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'sku' => 'SKU-MINE',
        ]);
        Product::factory()->create([
            'branch_id' => $other->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'sku' => 'SKU-THEIRS',
        ]);

        $rows = (new ProductsExport($this->branch->id))->collection();

        expect($rows->pluck(0)->all())->toBe(['SKU-MINE']);
    });

    it('creates a product from a row whose sku is new', function () {
        $response = $this->post(route('inventory.products.import'), [
            'file' => exportedProductsSheet([
                ['SKU-NEW', 'ورق A4', 'قرطاسية', 'قطعة', '12.00', '18.00', '5', '', 'لا', 1, '', ''],
            ]),
        ])->assertOk();

        expect($response->json('totalRows'))->toBe(1);
        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-NEW',
            'branch_id' => $this->branch->id,
            'name' => 'ورق A4',
            'cost_price' => 12,
            'selling_price' => 18,
        ]);
    });

    it('updates the product of the same sku instead of duplicating it', function () {
        Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'sku' => 'SKU-A',
            'selling_price' => 10,
        ]);

        $this->post(route('inventory.products.import'), [
            'file' => exportedProductsSheet([
                ['SKU-A', 'ورق A4', 'قرطاسية', 'قطعة', '12.00', '25.00', '5', '', 'لا', 1, '', ''],
            ]),
        ])->assertOk();

        $products = Product::where('sku', 'SKU-A')->get();

        expect($products)->toHaveCount(1)
            ->and((float) $products->first()->selling_price)->toBe(25.0);
    });

    it('rejects the row whose category name is unknown, with its reason', function () {
        $response = $this->post(route('inventory.products.import'), [
            'file' => exportedProductsSheet([
                ['SKU-X', 'ورق', 'فئة لا وجود لها', 'قطعة', '12.00', '18.00', '5', '', 'لا', 1, '', ''],
            ]),
        ])->assertOk();

        expect($response->json('skipped'))->toHaveCount(1)
            ->and($response->json('skipped.0.reason'))->toContain('فئة غير معروفة');
        $this->assertDatabaseMissing('products', ['sku' => 'SKU-X']);
    });

    it('never writes current_stock, and never a stock movement, from a sheet', function () {
        $product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'sku' => 'SKU-S',
            'current_stock' => 0,
        ]);

        app(RecordStockMovementAction::class)
            ->handle($product, StockMovementTypeEnum::OPENING_STOCK, 7);

        $response = $this->post(route('inventory.products.import'), [
            'file' => exportedProductsSheet([
                // العمود الأخير «المخزون الحالي» يحمل 999 — ويُتجاهل بصوتٍ عالٍ.
                ['SKU-S', 'ورق', 'قرطاسية', 'قطعة', '12.00', '18.00', '5', '', 'لا', 1, '', '999'],
            ]),
        ])->assertOk();

        $counts = collect($response->json('summary'))->pluck('value', 'key');

        expect($counts['stockIgnored'])->toBe(1)
            ->and($product->refresh()->current_stock)->toEqual(7)
            ->and(StockMovement::where('product_id', $product->id)->count())->toBe(1);
    });

    it('pins the import to the branch admin own branch', function () {
        $other = Branch::factory()->create();

        $this->post(route('inventory.products.import'), [
            'branch' => $other->id, // مُهمَل: الوجهة تأتي من الخادم لا من الطلب
            'file' => exportedProductsSheet([
                ['SKU-P', 'ورق', 'قرطاسية', 'قطعة', '12.00', '18.00', '5', '', 'لا', 1, '', ''],
            ]),
        ])->assertOk();

        $this->assertDatabaseHas('products', ['sku' => 'SKU-P', 'branch_id' => $this->branch->id]);
    });

    it('refuses a super admin import that names no branch', function () {
        $superAdmin = User::factory()->create();
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->actingAs($superAdmin)
            ->post(route('inventory.products.import'), [
                'file' => exportedProductsSheet([
                    ['SKU-Q', 'ورق', 'قرطاسية', 'قطعة', '12.00', '18.00', '5', '', 'لا', 1, '', ''],
                ]),
            ])
            ->assertSessionHasErrors('branch');

        $this->assertDatabaseMissing('products', ['sku' => 'SKU-Q']);
    });

    it('keeps the accountant out of the import', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        $this->actingAs($accountant)
            ->post(route('inventory.products.import'), [
                'file' => exportedProductsSheet([
                    ['SKU-R', 'ورق', 'قرطاسية', 'قطعة', '12.00', '18.00', '5', '', 'لا', 1, '', ''],
                ]),
            ])
            ->assertForbidden();
    });
});
