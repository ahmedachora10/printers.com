<?php

use App\Enums\Roles;
use App\Enums\ServicePricingTypeEnum;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * خدمة مسعّرة بالمتر المربع: سعر السطر سعرُ مترٍ مربع، وإجماليه = الكمية ×
 * مساحة القطعة (العرض × الطول) × ذلك السعر — يحتسبه الخادم.
 */
function makeSqmService(array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create(array_merge([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'pricing_type' => 'sqm',
        'price_per_sqm' => 100,
        'agent_commission_per_sqm' => 5,
        'is_tahazir' => false,
        'is_active' => true,
    ], $attrs));

    // BranchService is a Pivot (non-incrementing) — re-fetch for the id.
    return BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

describe('Square-meter priced services', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($this->employee);

        $this->sqmService = makeSqmService();
    });

    it('falls back to the service meter price when none is typed', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->sqmService->id,
                'qty' => 2,
                // لم يكتب الكاشير سعراً، فيؤول الأمر إلى المقاس × سعر المتر.
                'unit_price' => 0,
                'discount_pct' => 0,
                'width_cm' => 100,
                'height_cm' => 70,
            ]],
        ])->assertRedirect(route('pos.service.create'));

        $invoice = ServiceInvoice::firstOrFail();
        $line = $invoice->lines->firstOrFail();

        // 1.00m × 0.70m = 0.7 م² للقطعة، وقطعتان = 1.4 م² × 100 للمتر = 140.00
        expect((float) $line->unit_price)->toBe(100.00)
            ->and($line->unit_price_basis)->toBe(ServicePricingTypeEnum::Sqm)
            ->and((float) $line->width_cm)->toBe(100.00)
            ->and((float) $line->height_cm)->toBe(70.00)
            ->and((float) $line->subtotal)->toBe(140.00)
            // السعر شامل الضريبة: 140 يدفعها العميل، صافيها 121.74 وضريبتها 18.26
            ->and((float) $invoice->subtotal)->toBe(140.00)
            ->and((float) $invoice->vat_amount)->toBe(18.26)
            ->and((float) $invoice->total_amount)->toBe(140.00);
    });

    it('keeps a meter price typed over the service default', function () {
        // تاسك 44: الكاشير يتفق مع العميل على سعر مترٍ غير سعر الخدمة، فيكتبه
        // ويُحفظ كما هو — والأبعاد تضربه لتعطي إجمالي السطر.
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->sqmService->id,
                'qty' => 2,
                'unit_price' => 90,
                'discount_pct' => 0,
                'width_cm' => 100,
                'height_cm' => 70,
            ]],
        ])->assertRedirect(route('pos.service.create'));

        $line = ServiceInvoice::firstOrFail()->lines->firstOrFail();

        // 2 قطعة × 0.7 م² = 1.4 م² × 90 للمتر = 126.00
        expect((float) $line->unit_price)->toBe(90.00)
            ->and((float) $line->width_cm)->toBe(100.00)
            ->and((float) $line->height_cm)->toBe(70.00)
            ->and((float) $line->subtotal)->toBe(126.00);
    });

    it('still requires the dimensions even when a price is typed', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->sqmService->id,
                'qty' => 1,
                'unit_price' => 90,
                'discount_pct' => 0,
            ]],
        ])->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('rejects a sqm line without dimensions', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->sqmService->id,
                'qty' => 1,
                'unit_price' => 0,
                'discount_pct' => 0,
            ]],
        ])->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('applies the line discount to the area total', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->sqmService->id,
                'qty' => 1,
                'unit_price' => 0,
                'discount_pct' => 20,
                'width_cm' => 50,
                'height_cm' => 50,
            ]],
        ]);

        // 0.5 × 0.5 = 0.25 م² × 100 للمتر = 25.00، وخصم 20% → 20.00
        $invoice = ServiceInvoice::firstOrFail();
        expect((float) $invoice->lines->first()->unit_price)->toBe(100.00)
            ->and((float) $invoice->subtotal)->toBe(20.00);
    });

    it('still enforces the max discount ceiling on a sqm line', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->sqmService->id,
                'qty' => 1,
                'unit_price' => 0,
                'discount_pct' => 50,
                'width_cm' => 100,
                'height_cm' => 100,
            ]],
        ])->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('ignores dimensions sent for a unit-priced service', function () {
        $unitTemplate = ServiceTemplate::factory()->create();
        BranchService::create([
            'branch_id' => $this->branch->id,
            'service_template_id' => $unitTemplate->id,
            'base_commission_pct' => 10,
            'max_discount_pct' => 0,
            'is_tahazir' => false,
            'is_active' => true,
        ]);
        $unitService = BranchService::where('service_template_id', $unitTemplate->id)->firstOrFail();

        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $unitService->id,
                'qty' => 1,
                'unit_price' => 40,
                'discount_pct' => 0,
                'width_cm' => 100,
                'height_cm' => 70,
            ]],
        ]);

        $line = ServiceInvoice::firstOrFail()->lines->firstOrFail();
        // The client price stands and no dimensions are stored on a unit line.
        expect((float) $line->unit_price)->toBe(40.00)
            ->and($line->unit_price_basis)->toBe(ServicePricingTypeEnum::Unit)
            ->and($line->width_cm)->toBeNull()
            ->and($line->height_cm)->toBeNull();
    });

    it('bills the area, not the piece: 0.5 م² at 60 للمتر is 30', function () {
        // مثال العميل نفسه: 100×50 سم بسعر متر 60 = 30.00 شاملة الضريبة.
        $service = makeSqmService(['price_per_sqm' => 50, 'max_discount_pct' => 0]);

        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $service->id,
                'qty' => 1,
                'unit_price' => 60,
                'discount_pct' => 0,
                'width_cm' => 100,
                'height_cm' => 50,
            ]],
        ])->assertSessionHasNoErrors();

        $invoice = ServiceInvoice::firstOrFail();

        expect((float) $invoice->lines->firstOrFail()->subtotal)->toBe(30.00)
            ->and((float) $invoice->total_amount)->toBe(30.00)
            // 30 شاملة 15% → صافيها 26.09 وضريبتها 3.91
            ->and((float) $invoice->vat_amount)->toBe(3.91);
    });

    it('reads a legacy line — a piece price with no basis — back as a meter price', function () {
        // الأسطر المحفوظة قبل التغيير تحمل سعر القطعة ولا عمود يميّزها، فتُقسم
        // على مساحتها عند فتح الفاتورة للتعديل كي لا يتضاعف إجماليها.
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->sqmService->id,
                'qty' => 1,
                'unit_price' => 100,
                'discount_pct' => 0,
                'width_cm' => 100,
                'height_cm' => 50,
            ]],
        ])->assertSessionHasNoErrors();

        $invoice = ServiceInvoice::firstOrFail();
        // ارجع بالسطر إلى الشكل القديم: 50.00 للقطعة (0.5 م² × 100) بلا أساس.
        $invoice->lines()->update(['unit_price' => 50, 'unit_price_basis' => null]);

        // Inertia تُرسل 100.0 عدداً صحيحاً في JSON، فالمقارنة على 100 لا 100.0.
        $this->get(route('pos.service.edit', $invoice->id))
            ->assertInertia(fn ($page) => $page->where('invoice.lines.0.unitPrice', 100));
    });
});
