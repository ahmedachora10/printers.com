<?php

use App\Enums\Roles;
use App\Enums\ServicePricingTypeEnum;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A branch service carrying an optional default materials cost. */
function materialsService(array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create(array_merge([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 50,
        'max_discount_pct' => 50,
        'is_tahazir' => false,
        'has_materials' => false,
        'materials_cost' => 0,
        'is_active' => true,
    ], $attrs));

    return BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

/** A single-line DUE payload over the test's default service. */
function materialsPayload(array $lineAttrs = [], array $overrides = []): array
{
    return array_merge([
        'payment_method_id' => paymentMethodId(),
        'status' => 'due',
        'lines' => [array_merge([
            'branch_service_id' => test()->service->id,
            'qty' => 1,
            'unit_price' => 100,
            'discount_pct' => 0,
        ], $lineAttrs)],
    ], $overrides);
}

/** Approve the invoice as the branch admin — what realises the ledger. */
function approveMaterialsInvoice(ServiceInvoice $invoice): void
{
    test()->actingAs(test()->branchAdmin)
        ->patch(route('invoices.service.pay', payable($invoice)))
        ->assertRedirect();

    test()->actingAs(test()->employee);
}

describe('Materials cost (الخامات) before commission', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($this->employee);

        // Branch admins are linked through branches.owner_id, not users.branch_id.
        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->service = materialsService();

        // Both raisers earn the same rate, so a manager-raised invoice can be
        // compared against an employee-raised one figure for figure.
        foreach ([$this->employee, $this->branchAdmin] as $raiser) {
            UserService::create([
                'user_id' => $raiser->id,
                'branch_service_id' => $this->service->id,
                'commission_override_pct' => 50,
            ]);
        }
    });

    // ---- The client's worked example --------------------------------------

    it("matches the client's example: 100 with 20 materials earns 33.48 at 50%", function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload())->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();
        $line = $invoice->lines()->firstOrFail();

        // 100 ÷ 1.15 = 86.96 → − 20 خامات = 66.96 → × 50% = 33.48
        // (the client's 33.47 truncates the fraction; this project rounds).
        expect((float) $line->materials_cost)->toEqual(20.00)
            ->and((float) $line->materials_total)->toEqual(20.00)
            ->and((float) $line->commission_amount)->toEqual(33.48)
            ->and((float) $invoice->employee_commission)->toEqual(33.48);
    });

    it('leaves the customer total untouched — materials are internal only', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload())->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        // السعر شامل الضريبة: 100 يدفعها العميل، صافيها 86.96 وضريبتها 13.04
        expect((float) $invoice->subtotal)->toEqual(100.00)
            ->and((float) $invoice->vat_amount)->toEqual(13.04)
            ->and((float) $invoice->total_amount)->toEqual(100.00);
    });

    it('earns the full net commission when the line carries no materials', function () {
        $this->post(route('pos.service.store'), materialsPayload())->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        // 86.96 × 50% = 43.48 — the task-6 figure, unchanged.
        expect((float) $line->materials_total)->toEqual(0.00)
            ->and((float) $line->commission_amount)->toEqual(43.48);
    });

    // ---- Quantity ---------------------------------------------------------

    it('multiplies the cost by the quantity', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload(['qty' => 3]))->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        // 300 ÷ 1.15 = 260.87 → − 60 = 200.87 → × 50% = 100.44
        expect((float) $line->materials_cost)->toEqual(20.00)
            ->and((float) $line->materials_total)->toEqual(60.00)
            ->and((float) $line->commission_amount)->toEqual(100.44);
    });

    // ---- تاسك 63: the cost unit follows the service's pricing type ---------

    it("matches the client's sqm example: 10 per m² on 100×70 cm costs 7, not 10", function () {
        $service = materialsService([
            'pricing_type' => ServicePricingTypeEnum::Sqm,
            'price_per_sqm' => 100,
            'has_materials' => true,
            'materials_cost' => 10,
        ]);
        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $service->id,
            'commission_override_pct' => 50,
        ]);

        $this->post(route('pos.service.store'), materialsPayload([
            'branch_service_id' => $service->id,
            'unit_price' => 100,
            'width_cm' => 100,
            'height_cm' => 70,
        ]))->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        // المساحة 0.70 م² × 10 ر.س للمتر = 7.00 — لا 10 كما كان قبل التاسك 63.
        expect((float) $line->materials_cost)->toEqual(10.00)
            ->and((float) $line->materials_total)->toEqual(7.00);
    });

    it('multiplies the sqm cost by the whole line area, pieces included', function () {
        $service = materialsService([
            'pricing_type' => ServicePricingTypeEnum::Sqm,
            'price_per_sqm' => 100,
            'has_materials' => true,
            'materials_cost' => 10,
        ]);
        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $service->id,
            'commission_override_pct' => 50,
        ]);

        $this->post(route('pos.service.store'), materialsPayload([
            'branch_service_id' => $service->id,
            'qty' => 3,
            'unit_price' => 100,
            'width_cm' => 100,
            'height_cm' => 70,
        ]))->assertRedirect();

        // 3 قطع × 0.70 م² = 2.10 م² × 10 = 21.00
        expect((float) ServiceInvoice::firstOrFail()->lines()->firstOrFail()->materials_total)->toEqual(21.00);
    });

    it('shrinks the commission base by the area-scaled cost, not the flat amount', function () {
        $service = materialsService([
            'pricing_type' => ServicePricingTypeEnum::Sqm,
            'price_per_sqm' => 100,
            'has_materials' => true,
            'materials_cost' => 10,
        ]);
        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $service->id,
            'commission_override_pct' => 50,
        ]);

        $this->post(route('pos.service.store'), materialsPayload([
            'branch_service_id' => $service->id,
            'unit_price' => 100,
            'width_cm' => 100,
            'height_cm' => 70,
        ]))->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        // السطر 0.70 م² × 100 = 70.00 شاملة ← 60.87 صافية − 7.00 خامات = 53.87 × 50% = 26.94
        // (وبالمبلغ الثابت القديم 10 لكان الأساس 50.87 والعمولة 25.44).
        expect((float) $line->subtotal)->toEqual(70.00)
            ->and((float) $line->commission_amount)->toEqual(26.94);
    });

    it('keeps a per-unit service multiplying by pieces', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 10]);

        $this->post(route('pos.service.store'), materialsPayload(['qty' => 3]))->assertRedirect();

        expect((float) ServiceInvoice::firstOrFail()->lines()->firstOrFail()->materials_total)->toEqual(30.00);
    });

    // ---- Clamping ---------------------------------------------------------

    it('clamps at zero rather than paying a negative commission', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 500]);

        // مرفوعة من مدير الفرع: أرضية السعر (تاسك 65) تمنع الموظف من بيعٍ تحت
        // تكلفة الخامة أصلاً، فلا يبلغ هذا القصّ إلا من لا تلزمه الأرضية — أو
        // فاتورةٌ قديمة قبلها. والعمولة تُحسب لصاحب الفاتورة أياً كان دوره.
        $this->actingAs($this->branchAdmin)
            ->post(route('pos.service.store'), materialsPayload())
            ->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();
        $line = $invoice->lines()->firstOrFail();

        // The raw cost is still recorded — only the commission base is clamped.
        expect((float) $line->materials_total)->toEqual(500.00)
            ->and((float) $line->commission_amount)->toEqual(0.00)
            ->and((float) $invoice->employee_commission)->toEqual(0.00);
    });

    it('blocks the employee from raising that loss-making line at all', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 500]);

        $this->post(route('pos.service.store'), materialsPayload())
            ->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    // ---- تاسك 54: the employee reads the cost, never writes it -------------

    it('falls back to the service default when the POS sends no amount', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload())->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        expect((float) $line->materials_total)->toEqual(20.00)
            ->and((float) $line->commission_amount)->toEqual(33.48);
    });

    it("ignores an employee's override and keeps the service default", function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 40,
        ]))->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        // 40 would have left them 23.48; the service's 20 leaves 33.48.
        expect((float) $line->materials_cost)->toEqual(20.00)
            ->and((float) $line->commission_amount)->toEqual(33.48);
    });

    it('keeps the service materials even when the employee switches the toggle off', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => false,
        ]))->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        // Clearing the toggle raises the commission just as zeroing the amount
        // would, so it is ignored too.
        expect((float) $line->materials_total)->toEqual(20.00)
            ->and((float) $line->commission_amount)->toEqual(33.48);
    });

    it('charges an employee nothing on a service with no materials configured', function () {
        expect($this->service->has_materials)->toBeFalse();

        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 20,
        ]))->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        expect((float) $line->materials_total)->toEqual(0.00)
            ->and((float) $line->commission_amount)->toEqual(43.48);
    });

    // ---- تاسك 54: the branch admin still prices freely ---------------------

    it('lets a branch admin override the service default from the POS', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->actingAs($this->branchAdmin)
            ->post(route('pos.service.store'), materialsPayload([
                'has_materials' => true,
                'materials_cost' => 40,
            ]))->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        // 86.96 − 40 = 46.96 → × 50% = 23.48
        expect((float) $line->materials_cost)->toEqual(40.00)
            ->and((float) $line->commission_amount)->toEqual(23.48);
    });

    it('lets a branch admin clear the materials with the toggle', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->actingAs($this->branchAdmin)
            ->post(route('pos.service.store'), materialsPayload([
                'has_materials' => false,
            ]))->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        expect((float) $line->materials_total)->toEqual(0.00)
            ->and((float) $line->commission_amount)->toEqual(43.48);
    });

    it('lets a branch admin charge exceptional materials on a service with none', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('pos.service.store'), materialsPayload([
                'has_materials' => true,
                'materials_cost' => 20,
            ]))->assertRedirect();

        expect((float) ServiceInvoice::firstOrFail()->lines()->firstOrFail()->materials_total)->toEqual(20.00);
    });

    // ---- Isolation from the other commissions ------------------------------

    it('leaves the line commission owner and the agent rebate untouched', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'rebate', 'rate' => 10]);

        $this->post(route('pos.service.store'), materialsPayload([
            'agent_id' => $agent->id,
            'agent_commission_type' => 'percentage',
            'agent_commission_value' => 10,
        ], ['agent_ids' => [$agent->id]]))->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();
        $line = $invoice->lines()->firstOrFail();
        $pivot = $invoice->invoiceAgents()->firstOrFail();

        // Both agent figures still sit on the full net-of-VAT value (86.96),
        // untouched by the 20 that came off the employee's base.
        expect((float) $line->agent_commission_amount)->toEqual(8.70)
            ->and((float) $pivot->rebate_amount)->toEqual(8.70)
            ->and((float) $line->commission_amount)->toEqual(33.48);
    });

    // ---- The ledger --------------------------------------------------------

    it('writes the reduced amount to the immutable commission ledger on approval', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload())->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();
        expect(CommissionLedger::count())->toBe(0);

        approveMaterialsInvoice($invoice);

        expect((float) CommissionLedger::firstOrFail()->amount)->toEqual(33.48);
    });

    // ---- Editing -----------------------------------------------------------

    it('re-reads the service definition when a due invoice is re-edited', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload())->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        // Only the accountant's screen can move this number; the employee's edit
        // simply picks up whatever it now says.
        $this->service->update(['materials_cost' => 40]);

        $this->put(route('pos.service.update', $invoice), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 5,
        ]))->assertRedirect();

        $line = $invoice->fresh()->lines()->firstOrFail();

        expect((float) $line->materials_cost)->toEqual(40.00)
            ->and((float) $line->commission_amount)->toEqual(23.48);
    });

    it('seeds the edit screen from the saved line, not the service default', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload())->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        $this->service->update(['materials_cost' => 99]);

        $this->get(route('pos.service.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.lines.0.hasMaterials', true)
                ->where('invoice.lines.0.materialsCost', 20)
            );
    });

    // ---- Validation --------------------------------------------------------

    it('rejects a negative materials cost', function () {
        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => -5,
        ]))->assertSessionHasErrors('lines.0.materials_cost');
    });

    // ---- The commission report ---------------------------------------------

    it('totals the materials cost in the commission report', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload(['qty' => 3]))->assertRedirect();

        approveMaterialsInvoice(ServiceInvoice::firstOrFail());

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.commissions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totals.materials', 60)
                ->where('summary.0.materials', 60)
                ->where('lines.0.materials', 60)
            );
    });

    it('reports zero materials for invoices that carry none', function () {
        $this->post(route('pos.service.store'), materialsPayload())->assertRedirect();

        approveMaterialsInvoice(ServiceInvoice::firstOrFail());

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.commissions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totals.materials', 0));
    });

    // ---- Guard on the immutable ledger row -----------------------------------

    it('keeps the materials snapshot on the line after approval', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload())->assertRedirect();

        approveMaterialsInvoice(ServiceInvoice::firstOrFail());

        expect((float) ServiceInvoiceLine::firstOrFail()->materials_total)->toEqual(20.00);
    });
});
