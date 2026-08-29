<?php

use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * صمّام الأمان لقاعدة «السعر المُدخل شامل ضريبة القيمة المضافة»: ما يكتبه الموظف
 * في نقطة البيع هو ما يدفعه العميل بالضبط، والضريبة مستخرَجة من داخله. الاختبار
 * يثبّت الحدّين معاً — أن الإجمالي لا يزيد على المُدخل، وأن (الصافي + الضريبة)
 * يساوي الإجمالي بالقرش مهما وقع التقريب.
 */
describe('VAT-inclusive pricing', function () {
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
            'type' => StockMovementTypeEnum::OPENING_STOCK,
            'qty' => 10000,
            'created_by' => $this->accountant->id,
        ]);
        $this->product->refresh();
    });

    it('prints the client worked example: 100 in, 100 out', function () {
        $this->post(route('pos.product.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'paid',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
            ],
        ])->assertRedirect();

        $invoice = ProductInvoice::firstOrFail();

        // 100 ÷ 1.15 = 86.9565 → 86.96 صافياً، والضريبة الباقية 13.04.
        expect((float) $invoice->total_amount)->toBe(100.00)
            ->and((float) $invoice->vat_amount)->toBe(13.04)
            ->and(round((float) $invoice->total_amount - (float) $invoice->vat_amount, 2))->toBe(86.96);
    });

    it('keeps net + VAT equal to the total to the halala across awkward prices', function () {
        // أسعار مختارة لإيقاع التقريب في الحرج: كسور، وأرقام تنتهي بـ 5، ومبالغ
        // قسمتها على 1.15 لا تنتهي. الضريبة تُحسب بالطرح فلا يظهر فرق قرش.
        $prices = [0.01, 0.05, 0.33, 1, 1.01, 3.33, 7, 9.99, 10, 12.35, 33.33, 50, 66.67, 99.99, 100, 123.45, 250.05, 777.77, 1000, 1234.56];

        foreach ($prices as $price) {
            ProductInvoice::query()->forceDelete();

            $this->post(route('pos.product.store'), [
                'payment_method_id' => paymentMethodId(),
                'status' => 'paid',
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => $price, 'discount_pct' => 0],
                ],
            ])->assertRedirect();

            $invoice = ProductInvoice::firstOrFail();
            $total = (float) $invoice->total_amount;
            $vat = (float) $invoice->vat_amount;
            $net = round($total - $vat, 2);

            expect($total)->toBe(round($price, 2), "الإجمالي لسعر {$price}")
                ->and(round($net + $vat, 2))->toBe($total, "الصافي + الضريبة لسعر {$price}");
        }
    });

    it('leaves the total VAT-inclusive after a discount too', function () {
        $this->post(route('pos.product.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'paid',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 10],
            ],
        ])->assertRedirect();

        $invoice = ProductInvoice::firstOrFail();

        // خصم السطر 10% على 100 = 90 يدفعها العميل، ضريبتها المستخرجة 11.74.
        expect((float) $invoice->total_amount)->toBe(90.00)
            ->and((float) $invoice->vat_amount)->toBe(11.74);
    });

    it('adds no VAT at all in a zero-rate branch', function () {
        $this->branch->update(['vat_rate_override' => 0]);

        $this->post(route('pos.product.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'paid',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 25, 'discount_pct' => 0],
            ],
        ])->assertRedirect();

        $invoice = ProductInvoice::firstOrFail();

        expect((float) $invoice->vat_amount)->toBe(0.00)
            ->and((float) $invoice->total_amount)->toBe(50.00);
    });
});
