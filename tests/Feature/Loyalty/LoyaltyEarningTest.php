<?php

use App\Enums\CustomerTierEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/**
 * Drives loyalty earning through the real product POS endpoint so the wiring
 * inside CreateProductInvoiceAction is exercised end-to-end. Default payload:
 * 3 × 10 = 30 إجمالاً شاملاً الضريبة، وصافيه من الضريبة 30 ÷ 1.15 = 26.09 —
 * وهو أساس النقاط والإنفاق التراكمي.
 */
function loyaltyPayload(array $overrides = []): array
{
    return array_merge([
        'status' => 'paid',
        'lines' => [
            ['product_id' => test()->product->id, 'qty' => 3, 'unit_price' => 10, 'discount_pct' => 0],
        ],
    ], $overrides);
}

function makeIndividual(array $attrs = []): Customer
{
    return Customer::factory()->create(array_merge([
        'branch_id' => test()->branch->id,
        'customer_type' => 'individual',
        'agent_id' => null,
        'points_balance' => 0,
        'cumulative_spend' => 0,
        'tier' => CustomerTierEnum::None,
    ], $attrs));
}

describe('Loyalty earning', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($this->accountant);

        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => ProductCategory::factory()->create()->id,
            'unit_id' => ProductUnit::factory()->create()->id,
            'selling_price' => 10.00,
            'cost_price' => 6.00,
            'current_stock' => 0,
        ]);

        StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'type' => 'opening_stock',
            'qty' => 100,
            'created_by' => $this->accountant->id,
        ]);
    });

    it('credits FLOOR(net × earning_rate) points to an individual on a paid invoice', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'earning_rate' => 0.5]);
        $customer = makeIndividual();

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        // النقاط من الصافي: 26.09 × 0.5 = 13.045 → FLOOR = 13.
        // والإنفاق التراكمي من الإجمالي شامل الضريبة: 30.00.
        expect($customer->refresh()->points_balance)->toBe(13)
            ->and((float) $customer->cumulative_spend)->toBe(30.00);

        $invoice = ProductInvoice::firstOrFail();
        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'invoice_type' => ProductInvoice::class,
            'type' => 'earn',
            'points' => 13,
            'balance_after' => 13,
        ]);
    });

    // المقياسان مختلفان عن قصد: النقاط من الصافي، والإنفاق الذي تُقاس به حدود
    // الفئات من الإجمالي كما يدفعه العميل على الفاتورة.
    it('earns points net of VAT but accrues spend gross of it', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'earning_rate' => 1]);
        $customer = makeIndividual();

        // 10 × 11.50 = 115 شاملة الضريبة، وصافيها 100 بالتمام
        post(route('pos.product.store'), loyaltyPayload([
            'customer_id' => $customer->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 10, 'unit_price' => 11.5, 'discount_pct' => 0]],
        ]));

        $invoice = ProductInvoice::firstOrFail();

        expect((float) $invoice->total_amount)->toBe(115.00)
            ->and((float) $invoice->vat_amount)->toBe(15.00)
            ->and($customer->refresh()->points_balance)->toBe(100)
            ->and((float) $customer->cumulative_spend)->toBe(115.00);
    });

    it('accumulates points and balance_after across invoices', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'earning_rate' => 1]);
        $customer = makeIndividual(['points_balance' => 100, 'cumulative_spend' => 100]);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        // 100 + FLOOR(26.09) = 126
        expect($customer->refresh()->points_balance)->toBe(126);
        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $customer->id,
            'points' => 26,
            'balance_after' => 126,
        ]);
    });

    it('promotes the tier once cumulative spend crosses a threshold', function () {
        LoyaltyConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'earning_rate' => 1,
            'bronze_threshold' => 26,
            'silver_threshold' => 2000,
            'gold_threshold' => 5000,
        ]);
        $customer = makeIndividual();

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        // cumulative 26.09 ≥ bronze_threshold 26 → bronze
        expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Bronze);
    });

    // المستوى يُشتقّ من الإنفاق التراكمي عند كل اكتساب، فتنزيلٌ يدويٌّ يترك
    // الإنفاق فوق العتبة يُنقض عند أول فاتورة — ولهذا يقبل التنزيلُ تصحيحاً
    // للإنفاق معه.
    it('re-promotes after a manual downgrade that left the spend untouched', function () {
        LoyaltyConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'earning_rate' => 1,
            'bronze_threshold' => 26,
            'silver_threshold' => 2000,
        ]);
        $customer = makeIndividual(['tier' => CustomerTierEnum::None, 'cumulative_spend' => 2000]);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Silver);
    });

    it('keeps a manual downgrade that corrected the spend as well', function () {
        LoyaltyConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'earning_rate' => 1,
            'bronze_threshold' => 500,
            'silver_threshold' => 2000,
        ]);
        $customer = makeIndividual(['tier' => CustomerTierEnum::None, 'cumulative_spend' => 0]);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        expect($customer->refresh()->tier)->toBe(CustomerTierEnum::None);
    });

    // القاعدة القديمة كانت «الفئة لا تنزل أبداً»، فبقي عملاء معلَّقين في فئةٍ لا
    // يبلغها إنفاقهم. الفئة اليوم تتبع الإنفاق في الاتجاهين.
    it('drops a tier the spend no longer supports', function () {
        LoyaltyConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'earning_rate' => 1,
            'bronze_threshold' => 20,
            'silver_threshold' => 2000,
            'gold_threshold' => 5000,
        ]);
        // ذهبيٌّ بلا إنفاق: فاتورة صغيرة تُنزله إلى ما يبلغه إنفاقه فعلاً.
        $customer = makeIndividual(['tier' => CustomerTierEnum::Gold, 'cumulative_spend' => 0]);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        // 30.00 ناقص خصم الفئة الذهبية 8% = 27.60 شاملة الضريبة: تبلغ حدّ
        // البرونزي (20) ولا تبلغ الفضي، فينزل من الذهبي إلى البرونزي.
        expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Bronze)
            ->and((float) $customer->cumulative_spend)->toBe(27.60);
    });

    // بلوغ الحدّ يكفي، فلا يُشترط تجاوزه.
    it('grants the tier when the spend exactly meets the threshold', function () {
        LoyaltyConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'earning_rate' => 1,
            'bronze_threshold' => 30,
        ]);
        $customer = makeIndividual(['tier' => CustomerTierEnum::None, 'cumulative_spend' => 0]);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Bronze);
    });

    it('earns no points for a corporate customer', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id]);
        $customer = makeIndividual(['customer_type' => 'corporate', 'company_name' => 'شركة']);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        expect($customer->refresh()->points_balance)->toBe(0);
        expect(LoyaltyTransaction::count())->toBe(0);
    });

    it('earns no points for an agent-linked customer', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id]);
        $agentUser = User::factory()->create(['branch_id' => $this->branch->id]);
        $customer = makeIndividual(['agent_id' => $agentUser->id]);

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        expect($customer->refresh()->points_balance)->toBe(0);
        expect(LoyaltyTransaction::count())->toBe(0);
    });

    it('earns no points on a due invoice', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id]);
        $customer = makeIndividual();

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id, 'status' => 'due']));

        expect($customer->refresh()->points_balance)->toBe(0);
        expect(LoyaltyTransaction::count())->toBe(0);
    });

    it('earns no points when the loyalty program is inactive', function () {
        LoyaltyConfig::factory()->inactive()->create(['branch_id' => $this->branch->id]);
        $customer = makeIndividual();

        post(route('pos.product.store'), loyaltyPayload(['customer_id' => $customer->id]));

        expect($customer->refresh()->points_balance)->toBe(0);
        expect(LoyaltyTransaction::count())->toBe(0);
    });

    it('earns nothing for a cash sale with no customer', function () {
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id]);

        post(route('pos.product.store'), loyaltyPayload());

        expect(LoyaltyTransaction::count())->toBe(0);
    });
});
