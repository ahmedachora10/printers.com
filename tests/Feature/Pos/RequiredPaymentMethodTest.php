<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * طريقة الدفع إلزامية على كل فاتورة عند الإنشاء والتعديل — فاتورةٌ بلا طريقة
 * تسقط من تفصيل طرق الدفع في التقارير ويتعثّر اعتمادها عند المحاسب. ولا تُقبل
 * إلا طريقةٌ يراها فرع الفاتورة نفسه.
 */
describe('Payment method is mandatory on every invoice', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->cash = PaymentMethod::factory()->create(['name' => 'نقد', 'branch_id' => null]);

        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => ProductCategory::factory()->create()->id,
            'unit_id' => ProductUnit::factory()->create()->id,
            'selling_price' => 10.00,
            'cost_price' => 6.00,
            'current_stock' => 0,
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
    });

    // ── الإنشاء ────────────────────────────────────────────────────

    it('refuses a product invoice with no payment method', function () {
        $this->actingAs($this->accountant)
            ->post(route('pos.product.store'), [
                'status' => 'paid',
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])->assertSessionHasErrors('payment_method_id');

        $this->assertDatabaseCount('product_invoices', 0);
    });

    it('refuses a service invoice with no payment method', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])->assertSessionHasErrors('payment_method_id');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('demands one even on a due invoice the employee raises', function () {
        // الفاتورة الآجلة ليست استثناءً: الموظف يحدّد كيف سيُحصَّل المبلغ.
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'payment_method_id' => $this->cash->id,
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])->assertRedirect(route('pos.service.create'));

        expect(ServiceInvoice::firstOrFail()->payment_method_id)->toBe($this->cash->id);
    });

    it('rejects a payment method that belongs to another branch', function () {
        $foreign = PaymentMethod::factory()->create([
            'name' => 'طريقة فرع آخر',
            'branch_id' => Branch::factory()->create()->id,
        ]);

        $this->actingAs($this->accountant)
            ->post(route('pos.product.store'), [
                'status' => 'paid',
                'payment_method_id' => $foreign->id,
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])->assertSessionHasErrors('payment_method_id');

        $this->assertDatabaseCount('product_invoices', 0);
    });

    // ── التعديل ────────────────────────────────────────────────────

    it('refuses an edit that drops the payment method', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'payment_method_id' => $this->cash->id,
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ]);

        $invoice = ServiceInvoice::firstOrFail();

        $this->put(route('pos.service.update', $invoice), [
            'lines' => [
                ['branch_service_id' => $this->service->id, 'qty' => 2, 'unit_price' => 10, 'discount_pct' => 0],
            ],
        ])->assertSessionHasErrors('payment_method_id');

        expect($invoice->refresh()->lines()->first()->qty)->toBe(1);
    });

    it('forces a method onto an older invoice saved without one', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'payment_method_id' => $this->cash->id,
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ]);

        // فاتورة من قبل هذه القاعدة: حُفظت بلا طريقة دفع.
        $invoice = ServiceInvoice::firstOrFail();
        $invoice->forceFill(['payment_method_id' => null])->save();

        $lines = [
            ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
        ];

        $this->put(route('pos.service.update', $invoice), ['lines' => $lines])
            ->assertSessionHasErrors('payment_method_id');

        $this->put(route('pos.service.update', $invoice), [
            'payment_method_id' => $this->cash->id,
            'lines' => $lines,
        ])->assertRedirect();

        expect($invoice->refresh()->payment_method_id)->toBe($this->cash->id);
    });

    it('still accepts a method that was disabled after the invoice was raised', function () {
        $card = PaymentMethod::factory()->create(['name' => 'بطاقة', 'branch_id' => null]);

        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'payment_method_id' => $card->id,
                'lines' => [
                    ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ]);

        $invoice = ServiceInvoice::firstOrFail();
        $card->update(['is_active' => false]);

        // التعديل لا يتعثّر بطريقةٍ عُطّلت بعد إصدار الفاتورة — تبقى مقبولةً لها وحدها.
        $this->put(route('pos.service.update', $invoice), [
            'payment_method_id' => $card->id,
            'lines' => [
                ['branch_service_id' => $this->service->id, 'qty' => 2, 'unit_price' => 10, 'discount_pct' => 0],
            ],
        ])->assertRedirect();

        expect($invoice->refresh()->payment_method_id)->toBe($card->id)
            ->and($invoice->lines()->first()->qty)->toBe(2);
    });
});
