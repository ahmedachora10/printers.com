<?php

use App\Enums\Roles;
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
        ->patch(route('invoices.service.pay', $invoice))
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
        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 50,
        ]);
    });

    // ---- The client's worked example --------------------------------------

    it("matches the client's example: 100 with 20 materials earns 33.48 at 50%", function () {
        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 20,
        ]))->assertRedirect();

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
        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 20,
        ]))->assertRedirect();

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

    it('multiplies the entered cost by the quantity', function () {
        $this->post(route('pos.service.store'), materialsPayload([
            'qty' => 3,
            'has_materials' => true,
            'materials_cost' => 20,
        ]))->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        // 300 ÷ 1.15 = 260.87 → − 60 = 200.87 → × 50% = 100.44
        expect((float) $line->materials_cost)->toEqual(20.00)
            ->and((float) $line->materials_total)->toEqual(60.00)
            ->and((float) $line->commission_amount)->toEqual(100.44);
    });

    // ---- Clamping ---------------------------------------------------------

    it('clamps at zero rather than paying a negative commission', function () {
        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 500,
        ]))->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();
        $line = $invoice->lines()->firstOrFail();

        // The raw cost is still recorded — only the commission base is clamped.
        expect((float) $line->materials_total)->toEqual(500.00)
            ->and((float) $line->commission_amount)->toEqual(0.00)
            ->and((float) $invoice->employee_commission)->toEqual(0.00);
    });

    // ---- The service default ----------------------------------------------

    it('falls back to the service default when the POS sends no amount', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload())->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        expect((float) $line->materials_total)->toEqual(20.00)
            ->and((float) $line->commission_amount)->toEqual(33.48);
    });

    it('lets the POS override the service default', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 40,
        ]))->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        // 86.96 − 40 = 46.96 → × 50% = 23.48
        expect((float) $line->materials_total)->toEqual(40.00)
            ->and((float) $line->commission_amount)->toEqual(23.48);
    });

    it('charges nothing when the toggle is off, even on a service that has a default', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => false,
        ]))->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        expect((float) $line->materials_total)->toEqual(0.00)
            ->and((float) $line->commission_amount)->toEqual(43.48);
    });

    it('accepts an exceptional cost on a service with no materials configured', function () {
        expect($this->service->has_materials)->toBeFalse();

        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 20,
        ]))->assertRedirect();

        expect((float) ServiceInvoice::firstOrFail()->lines()->firstOrFail()->materials_total)->toEqual(20.00);
    });

    // ---- Isolation from the other commissions ------------------------------

    it('leaves the line commission owner and the agent rebate untouched', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($agent, $this->branch->id, ['discount_mode' => 'rebate', 'rate' => 10]);

        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 20,
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
        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 20,
        ]))->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();
        expect(CommissionLedger::count())->toBe(0);

        approveMaterialsInvoice($invoice);

        expect((float) CommissionLedger::firstOrFail()->amount)->toEqual(33.48);
    });

    // ---- Editing -----------------------------------------------------------

    it('recalculates when the materials cost is edited on a due invoice', function () {
        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 20,
        ]))->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        $this->put(route('pos.service.update', $invoice), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 40,
        ]))->assertRedirect();

        expect((float) $invoice->fresh()->lines()->firstOrFail()->commission_amount)->toEqual(23.48);
    });

    it('seeds the edit screen from the saved line, not the service default', function () {
        $this->service->update(['has_materials' => true, 'materials_cost' => 99]);

        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 20,
        ]))->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

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
        $this->post(route('pos.service.store'), materialsPayload([
            'qty' => 3,
            'has_materials' => true,
            'materials_cost' => 20,
        ]))->assertRedirect();

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
        $this->post(route('pos.service.store'), materialsPayload([
            'has_materials' => true,
            'materials_cost' => 20,
        ]))->assertRedirect();

        approveMaterialsInvoice(ServiceInvoice::firstOrFail());

        expect((float) ServiceInvoiceLine::firstOrFail()->materials_total)->toEqual(20.00);
    });
});
