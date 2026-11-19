<?php

use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\ProductUnit;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\PriceTiers;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** تاسك 241 — مثال العميل: ≤10 بـ10، ≤20 بـ8، ≤50 بـ6. */
const PIECE_TIERS = [
    ['to' => 10.0, 'price' => 10.0, 'min_price' => null, 'max_price' => null],
    ['to' => 20.0, 'price' => 8.0, 'min_price' => 7.0, 'max_price' => 9.0],
    ['to' => 50.0, 'price' => 6.0, 'min_price' => null, 'max_price' => null],
];

function tieredService(array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create(['branch_id' => test()->branch->id]);

    BranchService::create([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'is_active' => true,
        'price_tiers' => PIECE_TIERS,
        ...$attrs,
    ]);

    return BranchService::where('service_template_id', $template->id)->firstOrFail();
}

/** سطرٌ بلا سعر مرسَل — يأخذ سعر الشريحة. */
function tierLine(BranchService $service, array $attrs = []): array
{
    return ['branch_service_id' => $service->id, 'qty' => 1, 'unit_price' => 0, 'discount_pct' => 0, ...$attrs];
}

function tierSale(array $lines): array
{
    test()->post(route('pos.service.store'), ['payment_method_id' => paymentMethodId(), 'status' => 'due', 'lines' => $lines])
        ->assertSessionHasNoErrors();

    return ServiceInvoice::latest('id')->firstOrFail()->lines->map(fn ($l) => [(float) $l->unit_price, (float) $l->subtotal])->all();
}

it('picks the first tier whose «to» covers the quantity, the last beyond them', function () {
    expect(PriceTiers::tierFor(PIECE_TIERS, 5)['price'])->toBe(10.0)
        ->and(PriceTiers::tierFor(PIECE_TIERS, 10)['price'])->toBe(10.0)
        ->and(PriceTiers::tierFor(PIECE_TIERS, 10.5)['price'])->toBe(8.0)
        ->and(PriceTiers::tierFor(PIECE_TIERS, 60)['price'])->toBe(6.0)
        ->and(PriceTiers::tierFor(null, 5))->toBeNull()
        ->and(PriceTiers::tierFor([], 5))->toBeNull();
});

describe('Price tiers', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);
        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->actingAs($this->employee);
    });

    it('applies the reached tier to the whole quantity of each line on its own', function () {
        $service = tieredService();

        // 20 قطعة بـ8 = 160، لا جمع الشرائح السابقة.
        expect(tierSale([tierLine($service, ['qty' => 20])]))->toBe([[8.0, 160.0]])
            ->and(tierSale([tierLine($service, ['qty' => 5])]))->toBe([[10.0, 50.0]])
            ->and(tierSale([tierLine($service, ['qty' => 60])]))->toBe([[6.0, 360.0]])
            // سطران كلٌّ 10 قطع: كلٌّ بسعر 10، لا تُجمع كميتهما.
            ->and(tierSale([tierLine($service, ['qty' => 10]), tierLine($service, ['qty' => 10])]))->toBe([[10.0, 100.0], [10.0, 100.0]]);
    });

    it('measures a linear line by its total length and the next tier starts right after «to»', function () {
        $service = tieredService([
            'pricing_type' => 'linear',
            'price_per_sqm' => 25,
            'price_tiers' => [
                ['to' => 10.0, 'price' => 20.0, 'min_price' => null, 'max_price' => null],
                ['to' => 20.0, 'price' => 18.0, 'min_price' => null, 'max_price' => null],
                ['to' => null, 'price' => 15.0, 'min_price' => null, 'max_price' => null],
            ],
        ]);

        expect(tierSale([tierLine($service, ['width_cm' => 2500])])[0][0])->toBe(15.0)
            ->and(tierSale([tierLine($service, ['width_cm' => 1050])])[0][0])->toBe(18.0);
    });

    it('measures a square-metre line by its total area, not its piece count', function () {
        $service = tieredService([
            'pricing_type' => 'sqm',
            'price_per_sqm' => 30,
            'price_tiers' => [
                ['to' => 5.0, 'price' => 20.0, 'min_price' => null, 'max_price' => null],
                ['to' => null, 'price' => 12.0, 'min_price' => null, 'max_price' => null],
            ],
        ]);

        // 3 قطع × 2م × 1م = 6 م² ⇒ الشريحة الثانية.
        expect(tierSale([tierLine($service, ['qty' => 3, 'width_cm' => 200, 'height_cm' => 100])]))->toBe([[12.0, 72.0]]);
    });

    it('keeps a typed price but bounds the employee by the tier range', function () {
        $service = tieredService();

        expect(tierSale([tierLine($service, ['qty' => 15, 'unit_price' => 9])])[0][0])->toBe(9.0);

        $this->post(route('pos.service.store'), ['payment_method_id' => paymentMethodId(), 'status' => 'due', 'lines' => [tierLine($service, ['qty' => 15, 'unit_price' => 9.5])]])
            ->assertSessionHasErrors('lines');
        $this->post(route('pos.service.store'), ['payment_method_id' => paymentMethodId(), 'status' => 'due', 'lines' => [tierLine($service, ['qty' => 15, 'unit_price' => 6.5])]])
            ->assertSessionHasErrors('lines');

        // المدير غير مقيَّد بحدّي الشريحة.
        $this->actingAs($this->branchAdmin);
        expect(tierSale([tierLine($service, ['qty' => 15, 'unit_price' => 9.5])])[0][0])->toBe(9.5);
    });

    it('stores tiers sorted and rejects overlapping or out-of-bounds ones', function () {
        $service = tieredService(['price_tiers' => null]);
        $this->actingAs($this->branchAdmin);
        $base = ['base_commission_pct' => 10, 'max_discount_pct' => 20, 'pricing_type' => 'unit', 'is_active' => true];

        $this->put(route('branch-services.update', $service), [...$base, 'price_tiers' => [
            ['to' => null, 'price' => 6, 'min_price' => '', 'max_price' => ''],
            ['to' => 10, 'price' => 10, 'min_price' => '', 'max_price' => ''],
            ['to' => '', 'price' => '', 'min_price' => '', 'max_price' => ''],
        ]])->assertSessionHasNoErrors();
        // JSON يعيد 10.0 عدداً صحيحاً — toEqual لا toBe.
        expect($service->fresh()->price_tiers)->toEqual([
            ['to' => 10.0, 'price' => 10.0, 'min_price' => null, 'max_price' => null],
            ['to' => null, 'price' => 6.0, 'min_price' => null, 'max_price' => null],
        ]);

        $this->put(route('branch-services.update', $service), [...$base, 'price_tiers' => [['to' => 10, 'price' => 10], ['to' => 10, 'price' => 8]]])
            ->assertSessionHasErrors('price_tiers');
        $this->put(route('branch-services.update', $service), [...$base, 'price_tiers' => [['to' => null, 'price' => 10], ['to' => null, 'price' => 8]]])
            ->assertSessionHasErrors('price_tiers');
        $this->put(route('branch-services.update', $service), [...$base, 'price_tiers' => [['to' => 10, 'price' => 12, 'max_price' => 11]]])
            ->assertSessionHasErrors('price_tiers.0.price');
        // بلا حدّ للشريحة يرث أعلى سعر للخدمة.
        $this->put(route('branch-services.update', $service), [...$base, 'max_selling_price' => 9, 'price_tiers' => [['to' => 10, 'price' => 10]]])
            ->assertSessionHasErrors('price_tiers.0.price');
    });

    it('fills a product line from its tier when the POS sent no price', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => ProductCategory::factory()->create()->id,
            'unit_id' => ProductUnit::factory()->create()->id,
            'selling_price' => 10,
            'price_tiers' => PIECE_TIERS,
        ]);
        StockMovement::factory()->create([
            'product_id' => $product->id, 'branch_id' => $this->branch->id,
            'type' => StockMovementTypeEnum::OPENING_STOCK, 'qty' => 100, 'created_by' => $accountant->id,
        ]);

        $this->actingAs($accountant)->post(route('pos.product.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'paid',
            'lines' => [
                ['product_id' => $product->id, 'qty' => 20, 'unit_price' => 0, 'discount_pct' => 0],
                // سعرٌ يكتبه المحاسب يبقى — الشريحة اقتراحٌ له لا قيد.
                ['product_id' => $product->id, 'qty' => 20, 'unit_price' => 12, 'discount_pct' => 0],
            ],
        ])->assertSessionHasNoErrors();

        expect(ProductInvoice::firstOrFail()->lines->pluck('unit_price')->map(fn ($p) => (float) $p)->all())->toBe([8.0, 12.0]);
    });
});
