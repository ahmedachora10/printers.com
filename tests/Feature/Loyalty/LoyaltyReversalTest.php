<?php

use App\Enums\CustomerTierEnum;
use App\Enums\Roles;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\ProductUnit;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * تُغطّي فكّ أثر الولاء: منعُ النقاط عن فواتير الوسطاء، وعكسُ النقاط والإنفاق
 * التراكمي حين يُسترجع جزءٌ من الفاتورة أو كلّها.
 *
 * الفاتورة الافتراضية: 10 × 11.50 = 115 شاملة الضريبة، وصافيها 100 بالتمام —
 * وبمعدّل اكتساب 1 تساوي 100 نقطة، فتُقرأ نسبُ المرتجعات مباشرةً كنقاط.
 */
function reversalProductPayload(array $overrides = []): array
{
    return array_merge([
        'payment_method_id' => paymentMethodId(),
        'status' => 'paid',
        'lines' => [
            ['product_id' => test()->product->id, 'qty' => 10, 'unit_price' => 11.5, 'discount_pct' => 0],
        ],
    ], $overrides);
}

function reversalCustomer(array $attrs = []): Customer
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

/** يسجّل مرتجعاً على الفاتورة بصفة مدير الفرع — المحاسب ممنوع من مرتجع فاتورة معتمدة. */
function refundInvoice(ProductInvoice|ServiceInvoice $invoice, float $amount): void
{
    test()->actingAs(test()->branchAdmin)
        ->post(route('refunds.store'), [
            'source_type' => $invoice instanceof ProductInvoice ? 'product' : 'service',
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'reason' => 'اختبار',
        ])->assertRedirect();
}

/** فاتورة خدمات آجلة ينشئها الموظف ثم يعتمدها مدير الفرع، فتُحتسب نقاطها. */
function serviceInvoiceFor(?int $customerId, array $overrides = []): ServiceInvoice
{
    test()->actingAs(test()->employee)
        ->post(route('pos.service.store'), array_merge([
            'status' => 'due',
            'payment_method_id' => paymentMethodId(),
            'customer_id' => $customerId,
            'lines' => [
                ['branch_service_id' => test()->service->id, 'qty' => 10, 'unit_price' => 11.5, 'discount_pct' => 0],
            ],
        ], $overrides))->assertRedirect();

    return ServiceInvoice::latest('id')->firstOrFail();
}

function approveServiceInvoice(ServiceInvoice $invoice): void
{
    test()->actingAs(test()->branchAdmin)
        ->patch(route('invoices.service.pay', payable($invoice)))
        ->assertRedirect();
}

describe('Loyalty reversal', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);
        LoyaltyConfig::factory()->create(['branch_id' => $this->branch->id, 'earning_rate' => 1]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        // مدير الفرع مرتبط عبر branches.owner_id لا عبر users.branch_id.
        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

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
            'qty' => 500,
            'created_by' => $this->accountant->id,
        ]);

        $template = ServiceTemplate::factory()->create();
        BranchService::create([
            'branch_id' => $this->branch->id,
            'service_template_id' => $template->id,
            'base_commission_pct' => 10,
            'max_discount_pct' => 20,
            'is_tahazir' => false,
            'is_active' => true,
        ]);
        $this->service = BranchService::where('branch_id', $this->branch->id)
            ->where('service_template_id', $template->id)
            ->firstOrFail();

        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 10,
        ]);

        $this->actingAs($this->accountant);
    });

    describe('agent invoices earn no points', function () {
        // العمود agent_id حُذف من service_invoices لصالح جدول service_invoice_agent،
        // فقراءته كانت تُرجع null دائماً ويسقط الحارس بصمت.
        it('does not credit points on a service invoice that carries an agent', function () {
            $customer = reversalCustomer();
            $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
            setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'rebate', 'rate' => 10]);

            approveServiceInvoice(serviceInvoiceFor($customer->id, ['agent_ids' => [$agent->id]]));

            expect($customer->refresh()->points_balance)->toBe(0)
                ->and((float) $customer->cumulative_spend)->toBe(0.00);

            $this->assertDatabaseCount('loyalty_transactions', 0);
        });

        it('does not credit points on a product invoice that carries an agent', function () {
            $customer = reversalCustomer();
            $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
            setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'rebate', 'rate' => 10]);

            $this->post(route('pos.product.store'), reversalProductPayload([
                'customer_id' => $customer->id,
                'agent_id' => $agent->id,
            ]));

            expect($customer->refresh()->points_balance)->toBe(0);
            $this->assertDatabaseCount('loyalty_transactions', 0);
        });

        it('still credits points on a service invoice with no agent', function () {
            $customer = reversalCustomer();

            approveServiceInvoice(serviceInvoiceFor($customer->id));

            // النقاط من الصافي (100)، والإنفاق من الإجمالي شامل الضريبة (115).
            expect($customer->refresh()->points_balance)->toBe(100)
                ->and((float) $customer->cumulative_spend)->toBe(115.00);
        });
    });

    describe('refunds unwind the loyalty effect', function () {
        it('claws back every earned point and rolls back the spend on a full refund', function () {
            $customer = reversalCustomer();
            $this->post(route('pos.product.store'), reversalProductPayload(['customer_id' => $customer->id]));
            $invoice = ProductInvoice::firstOrFail();

            expect($customer->refresh()->points_balance)->toBe(100);

            refundInvoice($invoice, 115.00);

            expect($customer->refresh()->points_balance)->toBe(0)
                ->and((float) $customer->cumulative_spend)->toBe(0.00);

            $this->assertDatabaseHas('loyalty_transactions', [
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
                'invoice_type' => ProductInvoice::class,
                'type' => 'manual_adjust',
                'points' => -100,
                'balance_after' => 0,
            ]);
        });

        it('claws back only the refunded fraction on a partial refund', function () {
            $customer = reversalCustomer();
            $this->post(route('pos.product.store'), reversalProductPayload(['customer_id' => $customer->id]));
            $invoice = ProductInvoice::firstOrFail();

            refundInvoice($invoice, 46.00);

            // 46 من 115 = 40%، فيُسحب 40% من 100 نقطة. والإنفاق يُخصم منه المبلغ
            // المسترجَع كما هو شاملاً الضريبة: 115 − 46 = 69.
            expect($customer->refresh()->points_balance)->toBe(60)
                ->and((float) $customer->cumulative_spend)->toBe(69.00);
        });

        it('reaches exactly the full clawback across successive partial refunds', function () {
            $customer = reversalCustomer();
            $this->post(route('pos.product.store'), reversalProductPayload(['customer_id' => $customer->id]));
            $invoice = ProductInvoice::firstOrFail();

            refundInvoice($invoice, 38.33);
            expect($customer->refresh()->points_balance)->toBe(67); // FLOOR(100 × 0.3333) = 33

            refundInvoice($invoice, 38.33);
            expect($customer->refresh()->points_balance)->toBe(34); // FLOOR(100 × 0.6666) = 66

            refundInvoice($invoice, 38.34);

            // لا سحب مضاعف: المجموع ينتهي عند 100 نقطة بالضبط لا أكثر
            expect($customer->refresh()->points_balance)->toBe(0)
                ->and((float) $customer->cumulative_spend)->toBe(0.00)
                ->and((int) LoyaltyTransaction::where('type', 'manual_adjust')->sum('points'))->toBe(-100);
        });

        it('claws back points earned on a service invoice too', function () {
            $customer = reversalCustomer();
            $invoice = serviceInvoiceFor($customer->id);
            approveServiceInvoice($invoice);

            expect($customer->refresh()->points_balance)->toBe(100);

            refundInvoice($invoice, 115.00);

            expect($customer->refresh()->points_balance)->toBe(0)
                ->and((float) $customer->cumulative_spend)->toBe(0.00);
        });

        it('returns the points the customer redeemed on the refunded invoice', function () {
            // 500 نقطة ÷ 100 = 5 ر.س خصماً، فالإجمالي 110 وصافيه 95.65 → 95 نقطة
            $customer = reversalCustomer(['points_balance' => 500]);

            $this->post(route('pos.product.store'), reversalProductPayload([
                'customer_id' => $customer->id,
                'redeem_points' => 500,
            ]));

            $invoice = ProductInvoice::firstOrFail();
            expect((float) $invoice->total_amount)->toBe(110.00)
                ->and($customer->refresh()->points_balance)->toBe(95);

            refundInvoice($invoice, 110.00);

            // تُسحب الـ 95 المكتسبة وتُردّ الـ 500 المستبدلة
            expect($customer->refresh()->points_balance)->toBe(500);

            $this->assertDatabaseHas('loyalty_transactions', [
                'invoice_id' => $invoice->id,
                'type' => 'manual_adjust',
                'points' => 500,
                'balance_after' => 500,
            ]);
        });

        it('never drives the points balance below zero', function () {
            $customer = reversalCustomer();
            $this->post(route('pos.product.store'), reversalProductPayload(['customer_id' => $customer->id]));
            $invoice = ProductInvoice::firstOrFail();

            // أنفق العميل نقاطه في مكان آخر قبل أن يُسجَّل المرتجع
            $customer->update(['points_balance' => 30]);

            refundInvoice($invoice, 115.00);

            expect($customer->refresh()->points_balance)->toBe(0);
        });

        it('keeps the tier when the rolled-back spend still meets the threshold', function () {
            $customer = reversalCustomer(['tier' => CustomerTierEnum::Gold, 'cumulative_spend' => 5000]);
            $this->post(route('pos.product.store'), reversalProductPayload(['customer_id' => $customer->id]));
            $invoice = ProductInvoice::firstOrFail();

            // خصم الذهبي 8%: الإجمالي 92 والمكتسب 92 نقطة
            refundInvoice($invoice, (float) $invoice->fresh()->total_amount);

            // عاد الإنفاق إلى 5000 وهو حدّ الذهبي بالتمام، وبلوغُ الحدّ يكفي.
            expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Gold)
                ->and((float) $customer->cumulative_spend)->toBe(5000.00)
                ->and($customer->points_balance)->toBe(0);
        });

        // القاعدة القديمة كانت تُبقي الفئة معلَّقة فوق إنفاقٍ لم يعد يبلغها.
        it('drops the tier when the refund takes the spend below the threshold', function () {
            $customer = reversalCustomer(['tier' => CustomerTierEnum::Gold, 'cumulative_spend' => 4950]);
            $this->post(route('pos.product.store'), reversalProductPayload(['customer_id' => $customer->id]));
            $invoice = ProductInvoice::firstOrFail();

            // 4950 + 92 = 5042 فيبقى ذهبياً، ثم يعيده المرتجع إلى 4950 دون الحدّ.
            refundInvoice($invoice, (float) $invoice->fresh()->total_amount);

            expect($customer->refresh()->tier)->toBe(CustomerTierEnum::Silver)
                ->and((float) $customer->cumulative_spend)->toBe(4950.00);
        });

        it('leaves the cumulative spend alone for an invoice that never earned', function () {
            // فاتورة آجلة لم تُعتمد: لا اكتساب ولا إضافة للإنفاق التراكمي،
            // فلا يُخصم منه شيء عند المرتجع.
            $customer = reversalCustomer(['cumulative_spend' => 750, 'points_balance' => 40]);
            $invoice = serviceInvoiceFor($customer->id);

            refundInvoice($invoice, 115.00);

            expect((float) $customer->refresh()->cumulative_spend)->toBe(750.00)
                ->and($customer->points_balance)->toBe(40);
        });

        it('is a no-op for an invoice with no customer', function () {
            $this->post(route('pos.product.store'), reversalProductPayload());
            $invoice = ProductInvoice::firstOrFail();

            refundInvoice($invoice, 115.00);

            $this->assertDatabaseCount('loyalty_transactions', 0);
        });
    });
});
