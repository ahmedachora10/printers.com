<?php

use App\Enums\Roles;
use App\Enums\ServicePricingTypeEnum;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * خدمة مسعّرة بالمتر الطولي (تاسك 80): سعر السطر سعرُ مترٍ طولي، وإجماليه =
 * الكمية × طول القطعة × ذلك السعر. البُعد الواحد يُخزَّن في `width_cm`.
 */
function makeLinearService(array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create(array_merge([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'pricing_type' => 'linear',
        'price_per_sqm' => 5,
        'agent_commission_per_sqm' => 2,
        'is_tahazir' => false,
        'is_active' => true,
    ], $attrs));

    $service = BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();

    UserService::create([
        'user_id' => test()->employee->id,
        'branch_service_id' => $service->id,
        'commission_override_pct' => 10,
    ]);

    return $service;
}

describe('Linear-meter priced services', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($this->employee);
    });

    it("bills the client's example: 5 per metre × 2 m × 2 pieces = 20.00", function () {
        $service = makeLinearService();

        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $service->id,
                'qty' => 2,
                // لم يكتب البائع سعراً، فيؤول الأمر إلى سعر متر الخدمة.
                'unit_price' => 0,
                'discount_pct' => 0,
                'width_cm' => 200,
            ]],
        ])->assertRedirect(route('pos.service.create'));

        $invoice = ServiceInvoice::firstOrFail();
        $line = $invoice->lines->firstOrFail();

        // 200 سم = 2 م للقطعة، وقطعتان = 4 أمتار × 5 للمتر = 20.00
        expect((float) $line->unit_price)->toEqual(5.00)
            ->and($line->unit_price_basis)->toBe(ServicePricingTypeEnum::Linear)
            ->and((float) $line->width_cm)->toEqual(200.00)
            // البُعد الثاني يبقى فارغاً: الطولي يقيس مقاساً واحداً.
            ->and($line->height_cm)->toBeNull()
            ->and((float) $line->subtotal)->toEqual(20.00)
            ->and((float) $invoice->subtotal)->toEqual(20.00)
            ->and((float) $invoice->total_amount)->toEqual(20.00);
    });

    it('refuses a line with no length', function () {
        $service = makeLinearService();

        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $service->id,
                'qty' => 1,
                'unit_price' => 5,
                'discount_pct' => 0,
            ]],
        ])->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('keeps a metre price typed over the service default', function () {
        $service = makeLinearService();

        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $service->id,
                'qty' => 1,
                'unit_price' => 8,
                'discount_pct' => 0,
                'width_cm' => 250,
            ]],
        ])->assertRedirect();

        // 2.5 م × 8 = 20.00
        expect((float) ServiceInvoice::firstOrFail()->lines->firstOrFail()->subtotal)->toEqual(20.00);
    });

    it('multiplies the materials cost by the same metres', function () {
        // تاسك 63: تكلفة الخامة تُقاس بوحدة القياس نفسها.
        $service = makeLinearService(['has_materials' => true, 'materials_cost' => 3]);

        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $service->id,
                'qty' => 2,
                'unit_price' => 20,
                'discount_pct' => 0,
                'width_cm' => 200,
            ]],
        ])->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines->firstOrFail();

        // 4 أمتار × 3 = 12.00، والعمولة على (80 ÷ 1.15 − 12) × 10% = 5.76
        expect((float) $line->materials_cost)->toEqual(3.00)
            ->and((float) $line->materials_total)->toEqual(12.00)
            ->and((float) $line->commission_amount)->toEqual(5.76);
    });

    it('measures the price floor against the metre price', function () {
        // تاسك 65: الأرضية تقيس السعر المكتوب — وهو هنا سعر المتر الطولي.
        $service = makeLinearService(['min_selling_price' => 6]);

        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $service->id,
                'qty' => 1,
                // إجمالي السطر 25.00 يفوق الأرضية بكثير، لكن سعر المتر 5 دونها.
                'unit_price' => 5,
                'discount_pct' => 0,
                'width_cm' => 500,
            ]],
        ])->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('pays the commission owner per linear metre', function () {
        $service = makeLinearService();

        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'rebate', 'rate' => 0]);

        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $service->id,
                'qty' => 2,
                'unit_price' => 5,
                'discount_pct' => 0,
                'width_cm' => 200,
                'agent_id' => $agent->id,
                'agent_commission_type' => 'per_sqm',
                'agent_commission_value' => 2,
            ]],
        ])->assertRedirect();

        // 4 أمتار طولية × 2 = 8.00 — نفس الوحدات التي فُوتر بها السطر.
        expect((float) ServiceInvoice::firstOrFail()->lines->firstOrFail()->agent_commission_amount)->toEqual(8.00);
    });

    it('leaves a square-metre service exactly as it was', function () {
        $template = ServiceTemplate::factory()->create();

        BranchService::create([
            'branch_id' => $this->branch->id,
            'service_template_id' => $template->id,
            'base_commission_pct' => 10,
            'max_discount_pct' => 20,
            'pricing_type' => 'sqm',
            'price_per_sqm' => 100,
            'is_active' => true,
        ]);

        $sqm = BranchService::where('branch_id', $this->branch->id)
            ->where('service_template_id', $template->id)
            ->firstOrFail();

        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $sqm->id,
                'qty' => 2,
                'unit_price' => 0,
                'discount_pct' => 0,
                'width_cm' => 100,
                'height_cm' => 70,
            ]],
        ])->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines->firstOrFail();

        // 1.00 × 0.70 = 0.7 م² للقطعة، وقطعتان = 1.4 م² × 100 = 140.00
        expect((float) $line->subtotal)->toEqual(140.00)
            ->and($line->unit_price_basis)->toBe(ServicePricingTypeEnum::Sqm)
            ->and((float) $line->height_cm)->toEqual(70.00);
    });

    it('still refuses a per-unit service a per-measure commission', function () {
        $template = ServiceTemplate::factory()->create();

        BranchService::create([
            'branch_id' => $this->branch->id,
            'service_template_id' => $template->id,
            'base_commission_pct' => 10,
            'max_discount_pct' => 0,
            'pricing_type' => 'unit',
            'is_active' => true,
        ]);

        $unitService = BranchService::where('branch_id', $this->branch->id)
            ->where('service_template_id', $template->id)
            ->firstOrFail();

        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'rebate', 'rate' => 0]);

        $this->post(route('pos.service.store'), [
            'payment_method_id' => paymentMethodId(),
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $unitService->id,
                'qty' => 1,
                'unit_price' => 50,
                'discount_pct' => 0,
                'agent_id' => $agent->id,
                'agent_commission_type' => 'per_sqm',
                'agent_commission_value' => 2,
            ]],
        ])->assertSessionHasErrors('lines');
    });
});
