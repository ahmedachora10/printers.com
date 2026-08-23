<?php

use App\Enums\CustomerTypeEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use App\Models\PaymentMethod;
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
 * فاتورة خدمة آجلة من نقطة بيع الموظف: 3 × 10 = 30 قبل الخصم، والأسعار شاملة
 * للضريبة (15%).
 */
function deferredServicePayload(array $overrides = []): array
{
    return array_merge([
        'status' => 'due',
        'payment_method_id' => test()->cash->id,
        'lines' => [
            ['branch_service_id' => test()->service->id, 'qty' => 3, 'unit_price' => 10, 'discount_pct' => 0],
        ],
    ], $overrides);
}

describe('Deferred loyalty redemption', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);
        $this->cash = PaymentMethod::factory()->create(['is_active' => true, 'requires_attachment' => false]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $template = ServiceTemplate::factory()->create();
        BranchService::create([
            'branch_id' => $this->branch->id,
            'service_template_id' => $template->id,
            'base_commission_pct' => 10,
            'max_discount_pct' => 20,
            'is_tahazir' => false,
            'is_active' => true,
        ]);
        $this->service = BranchService::where('branch_id', $this->branch->id)->firstOrFail();

        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 10,
        ]);

        LoyaltyConfig::factory()->create([
            'branch_id' => $this->branch->id,
            'earning_rate' => 1,
            'redemption_rate' => 100, // 100 نقطة = 1 ر.س
            'min_redemption_points' => 500,
        ]);

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_type' => CustomerTypeEnum::Individual,
            'agent_id' => null,
            'points_balance' => 1000,
            'cumulative_spend' => 0,
        ]);

        $this->actingAs($this->employee);
    });

    // ---- فاتورة الخدمة: الحجز ثم الخصم عند الاعتماد -------------------------

    it('discounts the invoice but leaves the balance untouched until approval', function () {
        $this->post(route('pos.service.store'), deferredServicePayload([
            'customer_id' => $this->customer->id,
            'redeem_points' => 500,
        ]));

        $invoice = ServiceInvoice::firstOrFail();

        // الخصم يظهر على الفاتورة من أول لحظة: 30 − 5 = 25 شاملاً الضريبة.
        expect((int) $invoice->points_redeemed)->toBe(500)
            ->and((float) $invoice->points_discount)->toBe(5.00)
            ->and((float) $invoice->total_amount)->toBe(25.00)
            // ولم يُخصم من الرصيد شيء بعد، ولا حركة ولاء كُتبت.
            ->and($invoice->points_redeemed_at)->toBeNull()
            ->and($this->customer->refresh()->points_balance)->toBe(1000)
            ->and(LoyaltyTransaction::count())->toBe(0);
    });

    it('spends the points when the accountant approves the invoice', function () {
        $this->post(route('pos.service.store'), deferredServicePayload([
            'customer_id' => $this->customer->id,
            'redeem_points' => 500,
        ]));

        $invoice = ServiceInvoice::firstOrFail();

        $this->actingAs($this->accountant)
            ->patch(route('invoices.service.pay', $invoice))
            ->assertSessionHasNoErrors();

        // 1000 − 500 مستبدلة = 500، ثم اكتساب FLOOR(25 ÷ 1.15) = 21 → 521.
        expect($this->customer->refresh()->points_balance)->toBe(521)
            ->and($invoice->refresh()->points_redeemed_at)->not->toBeNull();

        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'type' => 'redeem',
            'points' => -500,
            'balance_after' => 500,
        ]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $this->customer->id,
            'type' => 'earn',
            'balance_after' => 521,
        ]);
    });

    // ---- الحجز يمنع استبدال النقاط نفسها مرتين -----------------------------

    it('reserves the points against a second unapproved invoice', function () {
        $this->customer->update(['points_balance' => 900]);

        $this->post(route('pos.service.store'), deferredServicePayload([
            'customer_id' => $this->customer->id,
            'redeem_points' => 500,
        ]));

        // بقي 400 متاحاً فقط، فطلبُ 500 أخرى يُرفض ولا تُنشأ فاتورة ثانية.
        $this->post(route('pos.service.store'), deferredServicePayload([
            'customer_id' => $this->customer->id,
            'redeem_points' => 500,
        ]))->assertSessionHasErrors('redeem_points');

        expect(ServiceInvoice::count())->toBe(1)
            ->and($this->customer->refresh()->points_balance)->toBe(900);
    });

    it('does not count an invoice against itself while it is being edited', function () {
        $this->customer->update(['points_balance' => 500]);

        $this->post(route('pos.service.store'), deferredServicePayload([
            'customer_id' => $this->customer->id,
            'redeem_points' => 500,
        ]));

        $invoice = ServiceInvoice::firstOrFail();

        $this->put(route('pos.service.update', $invoice), deferredServicePayload([
            'customer_id' => $this->customer->id,
            'redeem_points' => 500,
            'lines' => [
                ['branch_service_id' => $this->service->id, 'qty' => 4, 'unit_price' => 10, 'discount_pct' => 0],
            ],
        ]))->assertSessionHasNoErrors();

        // 40 − 5 = 35، والنقاط ما زالت محجوزة لا مخصومة.
        expect((float) $invoice->refresh()->total_amount)->toBe(35.00)
            ->and((int) $invoice->points_redeemed)->toBe(500)
            ->and($invoice->points_redeemed_at)->toBeNull()
            ->and($this->customer->refresh()->points_balance)->toBe(500);
    });

    it('frees the reservation when the invoice is returned, with no loyalty row', function () {
        $this->post(route('pos.service.store'), deferredServicePayload([
            'customer_id' => $this->customer->id,
            'redeem_points' => 500,
        ]));

        $invoice = ServiceInvoice::firstOrFail();

        $this->post(route('pos.service.return', $invoice), ['reason' => 'إلغاء العمل'])
            ->assertSessionHasNoErrors();

        expect($this->customer->refresh()->points_balance)->toBe(1000)
            ->and(LoyaltyTransaction::count())->toBe(0);

        // وقد تحرّر الحجز، فبإمكانه استبدالها كلها على فاتورة جديدة.
        $this->post(route('pos.service.store'), deferredServicePayload([
            'customer_id' => $this->customer->id,
            'redeem_points' => 1000,
        ]))->assertSessionHasNoErrors();
    });

    // ---- فاتورة المنتجات: الخصم عند اكتمال السداد ---------------------------

    it('spends the points of a due product invoice only when it is settled', function () {
        $this->actingAs($this->accountant);

        $product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => ProductCategory::factory()->create()->id,
            'unit_id' => ProductUnit::factory()->create()->id,
            'selling_price' => 10.00,
            'cost_price' => 6.00,
            'current_stock' => 0,
        ]);

        StockMovement::factory()->create([
            'product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'type' => 'opening_stock',
            'qty' => 100,
            'created_by' => $this->accountant->id,
        ]);

        $this->post(route('pos.product.store'), [
            'status' => 'due',
            'customer_id' => $this->customer->id,
            'redeem_points' => 500,
            'payment_method_id' => $this->cash->id,
            'lines' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 10, 'discount_pct' => 0]],
        ])->assertSessionHasNoErrors();

        $invoice = ProductInvoice::firstOrFail();

        expect((float) $invoice->total_amount)->toBe(25.00)
            ->and($this->customer->refresh()->points_balance)->toBe(1000);

        // دفعة جزئية لا تعتمد الفاتورة، فلا تُخصم النقاط بعد.
        $this->post(route('invoices.payments.store', ['type' => 'product', 'id' => $invoice->id]), [
            'payment_method_id' => $this->cash->id,
            'amount' => 10,
        ])->assertSessionHasNoErrors();

        expect($this->customer->refresh()->points_balance)->toBe(1000);

        $this->post(route('invoices.payments.store', ['type' => 'product', 'id' => $invoice->id]), [
            'payment_method_id' => $this->cash->id,
            'amount' => 15,
        ])->assertSessionHasNoErrors();

        // اكتمل السداد: 1000 − 500 = 500 ثم اكتساب 21 → 521.
        expect($this->customer->refresh()->points_balance)->toBe(521)
            ->and($invoice->refresh()->points_redeemed_at)->not->toBeNull();
    });
});
