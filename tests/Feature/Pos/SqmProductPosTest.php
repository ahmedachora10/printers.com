<?php

use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\StockReconciliation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** منتج للفرع برصيد افتتاحي عشري، بالقطعة أو بالمتر المربع. */
function sqmTestProduct(float $openingStock, array $attrs = []): Product
{
    $product = Product::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'category_id' => test()->category->id,
        'unit_id' => test()->unit->id,
        'cost_price' => 6.00,
        'selling_price' => 10.00,
        'min_stock_level' => 0,
        'current_stock' => 0,
        'is_sqm' => false,
    ], $attrs));

    StockMovement::factory()->create([
        'product_id' => $product->id,
        'branch_id' => test()->branch->id,
        'type' => StockMovementTypeEnum::OPENING_STOCK,
        'qty' => $openingStock,
        'created_by' => test()->accountant->id,
    ]);

    return $product->refresh();
}

describe('Products priced by the square metre (تاسك 51)', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        // المخزون (الحركات اليدوية، الجرد، تعريف المنتجات، مرتجع فاتورة معتمدة)
        // من صلاحية مدير الفرع؛ ونقطة البيع من صلاحية المحاسب.
        $this->branchAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->actingAs($this->accountant);

        $this->category = ProductCategory::factory()->create();
        $this->unit = ProductUnit::factory()->create();
    });

    // ---- المخزون صار عشرياً ------------------------------------------------

    it('keeps a fractional quantity through the whole ledger', function () {
        $product = sqmTestProduct(10.5);

        expect($product->current_stock)->toEqual(10.5);

        StockMovement::factory()->create([
            'product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementTypeEnum::SALE_OUT,
            'qty' => -0.25,
            'created_by' => $this->accountant->id,
        ]);

        expect($product->refresh()->current_stock)->toEqual(10.25);
    });

    it('accepts a fractional manual adjustment from the stock screen', function () {
        $product = sqmTestProduct(10);

        $this->actingAs($this->branchAdmin)
            ->post(route('inventory.stock-movements.store'), [
                'product_id' => $product->id,
                'type' => StockMovementTypeEnum::ADJUSTMENT_OUT->value,
                'qty' => 0.75,
            ])->assertRedirect();

        expect($product->refresh()->current_stock)->toEqual(9.25);
    });

    // ---- البيع بالمتر المربع ----------------------------------------------

    it('derives a sqm line quantity from its dimensions and piece count', function () {
        // 120×80 سم = 0.96 م² للقطعة × قطعتين = 1.92 م²، وسعر المتر 50
        $product = sqmTestProduct(20, ['is_sqm' => true, 'selling_price' => 50]);

        $this->post(route('pos.product.store'), [
            'status' => 'paid',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1.92,
                'width_cm' => 120,
                'height_cm' => 80,
                'pieces' => 2,
                'unit_price' => 50,
                'discount_pct' => 0,
            ]],
        ])->assertRedirect();

        $line = ProductInvoice::firstOrFail()->lines()->firstOrFail();

        expect($line->qty)->toEqual(1.92)
            ->and((float) $line->width_cm)->toEqual(120.0)
            ->and((float) $line->height_cm)->toEqual(80.0)
            ->and($line->pieces)->toBe(2)
            // 1.92 م² × 50 = 96
            ->and((float) $line->subtotal)->toEqual(96.00);
    });

    it('deducts the sold area from stock, not the piece count', function () {
        $product = sqmTestProduct(20, ['is_sqm' => true, 'selling_price' => 50]);

        $this->post(route('pos.product.store'), [
            'status' => 'paid',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 0.5,
                'width_cm' => 100,
                'height_cm' => 50,
                'pieces' => 1,
                'unit_price' => 50,
                'discount_pct' => 0,
            ]],
        ])->assertRedirect();

        expect($product->refresh()->current_stock)->toEqual(19.5);

        $movement = StockMovement::where('type', StockMovementTypeEnum::SALE_OUT)->firstOrFail();

        expect((float) $movement->qty)->toEqual(-0.5);
    });

    it('recomputes the sqm quantity server-side and ignores a tampered one', function () {
        $product = sqmTestProduct(20, ['is_sqm' => true, 'selling_price' => 50]);

        $this->post(route('pos.product.store'), [
            'status' => 'paid',
            'lines' => [[
                'product_id' => $product->id,
                // كمية ملفَّقة أصغر بكثير من المساحة الحقيقية
                'qty' => 0.01,
                'width_cm' => 100,
                'height_cm' => 100,
                'pieces' => 3,
                'unit_price' => 50,
                'discount_pct' => 0,
            ]],
        ])->assertRedirect();

        // 1 م² × 3 = 3 م² — المعتمَد اشتقاق الخادم
        expect(ProductInvoice::firstOrFail()->lines()->firstOrFail()->qty)->toEqual(3.0)
            ->and($product->refresh()->current_stock)->toEqual(17.0);
    });

    it('refuses a sqm product sold without its dimensions', function () {
        $product = sqmTestProduct(20, ['is_sqm' => true, 'selling_price' => 50]);

        $this->post(route('pos.product.store'), [
            'status' => 'paid',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => 50,
                'discount_pct' => 0,
            ]],
        ])->assertSessionHasErrors('lines');

        expect(ProductInvoice::count())->toBe(0)
            ->and($product->refresh()->current_stock)->toEqual(20.0);
    });

    it('refuses a sqm sale whose area exceeds the stock on hand', function () {
        $product = sqmTestProduct(1, ['is_sqm' => true, 'selling_price' => 50]);

        $this->post(route('pos.product.store'), [
            'status' => 'paid',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 2,
                'width_cm' => 200,
                'height_cm' => 100,
                'pieces' => 1,
                'unit_price' => 50,
                'discount_pct' => 0,
            ]],
        ])->assertSessionHasErrors('lines');

        expect(ProductInvoice::count())->toBe(0)
            ->and($product->refresh()->current_stock)->toEqual(1.0);
    });

    // ---- منتج القطعة لم يتغيّر --------------------------------------------

    it('leaves a piece-priced product on whole quantities and ignores stray dimensions', function () {
        $product = sqmTestProduct(20, ['selling_price' => 10]);

        $this->post(route('pos.product.store'), [
            'status' => 'paid',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 3,
                // مقاسٌ لا معنى له لمنتج بالقطعة — يُهمَل ولا يُخزَّن
                'width_cm' => 100,
                'height_cm' => 100,
                'unit_price' => 10,
                'discount_pct' => 0,
            ]],
        ])->assertRedirect();

        $line = ProductInvoice::firstOrFail()->lines()->firstOrFail();

        expect($line->qty)->toEqual(3.0)
            ->and($line->width_cm)->toBeNull()
            ->and($line->height_cm)->toBeNull()
            ->and($line->pieces)->toBeNull()
            ->and($product->refresh()->current_stock)->toEqual(17.0);
    });

    // ---- المرتجع يعيد المساحة نفسها ---------------------------------------

    it('returns the sold area to stock on a refund', function () {
        $product = sqmTestProduct(20, ['is_sqm' => true, 'selling_price' => 50]);

        $this->post(route('pos.product.store'), [
            'status' => 'paid',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 1.5,
                'width_cm' => 150,
                'height_cm' => 100,
                'pieces' => 1,
                'unit_price' => 50,
                'discount_pct' => 0,
            ]],
        ])->assertRedirect();

        $invoice = ProductInvoice::firstOrFail();

        expect($product->refresh()->current_stock)->toEqual(18.5);

        // المحاسب ممنوع من مرتجع فاتورة معتمدة (تاسك 40) — المدير هو من يردّها.
        $this->actingAs($this->branchAdmin)
            ->post(route('refunds.store'), [
                'source_type' => 'product',
                'invoice_id' => $invoice->id,
                'amount' => (float) $invoice->total_amount,
                'reason' => 'العميل ألغى الطلب',
                'reverse_stock' => true,
            ])->assertRedirect();

        expect($product->refresh()->current_stock)->toEqual(20.0);
    });

    // ---- الجرد يوازن العشري ------------------------------------------------

    it('reconciles a fractional variance', function () {
        $product = sqmTestProduct(10, ['is_sqm' => true]);

        $this->actingAs($this->branchAdmin);

        $this->post(route('inventory.stock-reconciliations.store'))->assertRedirect();

        $reconciliation = StockReconciliation::latest('id')->firstOrFail();
        $line = $reconciliation->lines()->where('product_id', $product->id)->firstOrFail();

        $this->put(route('inventory.stock-reconciliations.counts', $reconciliation), [
            'counts' => [['line_id' => $line->id, 'physical_qty' => 9.25]],
        ])->assertRedirect();

        expect($line->refresh()->variance)->toEqual(-0.75);

        $this->post(route('inventory.stock-reconciliations.complete', $reconciliation))->assertRedirect();

        expect($product->refresh()->current_stock)->toEqual(9.25);
    });

    // ---- تعريف المنتج ------------------------------------------------------

    it('stores the sqm flag and a fractional minimum stock level', function () {
        $this->actingAs($this->branchAdmin)->post(route('inventory.products.store'), [
            'name' => 'فينيل لاصق',
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'is_sqm' => true,
            'cost_price' => 20,
            'selling_price' => 35,
            'min_stock_level' => 2.5,
        ])->assertRedirect();

        $product = Product::where('name', 'فينيل لاصق')->firstOrFail();

        expect($product->is_sqm)->toBeTrue()
            ->and($product->min_stock_level)->toEqual(2.5);
    });
});
