<?php

use App\Enums\Roles;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A branch service for line-commission scenarios (unit or sqm priced). */
function commissionService(array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create(array_merge([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 0,
        'is_tahazir' => false,
        'is_active' => true,
    ], $attrs));

    return BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

/** The default 3 × 10 line carrying the given commission-owner terms. */
function lineWithAgent(array $agentAttrs, array $lineAttrs = []): array
{
    return [
        'status' => 'due',
        'lines' => [array_merge([
            'branch_service_id' => test()->service->id,
            'qty' => 3,
            'unit_price' => 10,
            'discount_pct' => 0,
        ], $agentAttrs, $lineAttrs)],
    ];
}

describe('Per-line agent commission (صاحب العمولة)', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($this->employee);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->service = commissionService();
        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 10,
        ]);

        $this->agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        setAgentBranchTerms($this->agent, $this->branch->id, ['discount_mode' => 'rebate', 'rate' => 10]);
    });

    it('computes a percentage commission on the line subtotal, without touching the total', function () {
        $this->post(route('pos.service.store'), lineWithAgent([
            'agent_id' => $this->agent->id,
            'agent_commission_type' => 'percentage',
            'agent_commission_value' => 10,
        ]))->assertRedirect(route('pos.service.create'));

        $invoice = ServiceInvoice::firstOrFail();
        $line = $invoice->lines->firstOrFail();
        $pivot = $invoice->invoiceAgents()->firstOrFail();

        // Line subtotal 30, net of VAT 26.09 → 10% = 2.61. السعر شامل الضريبة
        // فالإجمالي يبقى 30، والصف المحوري بلا خصم ولا ريبيت على مستوى الفاتورة.
        expect((float) $line->agent_commission_amount)->toBe(2.61)
            ->and($line->agent_id)->toBe($this->agent->id)
            ->and($line->agent_commission_type->value)->toBe('percentage')
            ->and((float) $invoice->total_amount)->toBe(30.00)
            ->and((float) $invoice->agent_discount)->toBe(0.00)
            ->and($pivot->agent_id)->toBe($this->agent->id)
            ->and((float) $pivot->discount_amount)->toBe(0.00)
            ->and((float) $pivot->rebate_amount)->toBe(0.00)
            ->and((float) $pivot->line_commission_amount)->toBe(2.61);
    });

    it('multiplies a fixed commission by the quantity', function () {
        $this->post(route('pos.service.store'), lineWithAgent([
            'agent_id' => $this->agent->id,
            'agent_commission_type' => 'fixed',
            'agent_commission_value' => 7,
        ]));

        // 7 ر.س × 3 قطع = 21.00
        $invoice = ServiceInvoice::firstOrFail();
        expect((float) $invoice->lines->first()->agent_commission_amount)->toBe(21.00)
            ->and((float) $invoice->invoiceAgents()->first()->line_commission_amount)->toBe(21.00);
    });

    it('computes a per-sqm commission from the dimensions', function () {
        $sqm = commissionService([
            'pricing_type' => 'sqm',
            'price_per_sqm' => 100,
            'agent_commission_per_sqm' => 5,
        ]);

        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $sqm->id,
                'qty' => 2,
                'unit_price' => 0,
                'discount_pct' => 0,
                'width_cm' => 100,
                'height_cm' => 70,
                'agent_id' => $this->agent->id,
                'agent_commission_type' => 'per_sqm',
                'agent_commission_value' => 5,
            ]],
        ]);

        // 2 قطع × 0.7 م² × 5 = 7.00
        $invoice = ServiceInvoice::firstOrFail();
        expect((float) $invoice->lines->first()->agent_commission_amount)->toBe(7.00)
            ->and((float) $invoice->invoiceAgents()->first()->line_commission_amount)->toBe(7.00);
    });

    it('rejects a per-sqm commission on a unit-priced service', function () {
        $this->post(route('pos.service.store'), lineWithAgent([
            'agent_id' => $this->agent->id,
            'agent_commission_type' => 'per_sqm',
            'agent_commission_value' => 5,
        ]))->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('requires the commission type and value when an agent is picked', function () {
        $this->post(route('pos.service.store'), lineWithAgent([
            'agent_id' => $this->agent->id,
        ]))->assertSessionHasErrors(['lines.0.agent_commission_type', 'lines.0.agent_commission_value']);
    });

    it('rejects a line agent from another branch', function () {
        $other = Agent::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->post(route('pos.service.store'), lineWithAgent([
            'agent_id' => $other->id,
            'agent_commission_type' => 'percentage',
            'agent_commission_value' => 10,
        ]))->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('merges an invoice-level rebate and line commissions onto one pivot row', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'agent_ids' => [$this->agent->id],
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 3,
                'unit_price' => 10,
                'discount_pct' => 0,
                'agent_id' => $this->agent->id,
                'agent_commission_type' => 'percentage',
                'agent_commission_value' => 10,
            ]],
        ]);

        $invoice = ServiceInvoice::firstOrFail();

        // One row for the agent. Both are earned net of VAT (26.09): rebate
        // 10% = 2.61 plus line commission 10% = 2.61.
        expect($invoice->invoiceAgents()->count())->toBe(1);

        $pivot = $invoice->invoiceAgents()->firstOrFail();
        expect((float) $pivot->rebate_amount)->toBe(2.61)
            ->and((float) $pivot->line_commission_amount)->toBe(2.61);
    });

    it('earns a percentage line commission on the value net of VAT', function () {
        // The client's worked example applied to a commission owner:
        // 100 / 1.15 = 86.96, at 50% = 43.48 — not 50.00 on the gross.
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 1,
                'unit_price' => 100,
                'discount_pct' => 0,
                'agent_id' => $this->agent->id,
                'agent_commission_type' => 'percentage',
                'agent_commission_value' => 50,
            ]],
        ])->assertRedirect(route('pos.service.create'));

        $invoice = ServiceInvoice::firstOrFail();

        expect((float) $invoice->lines->first()->agent_commission_amount)->toBe(43.48)
            ->and((float) $invoice->invoiceAgents()->first()->line_commission_amount)->toBe(43.48);
    });

    it('leaves a fixed line commission whole — there is no VAT inside a flat rate', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 3,
                'unit_price' => 100,
                'discount_pct' => 0,
                'agent_id' => $this->agent->id,
                'agent_commission_type' => 'fixed',
                'agent_commission_value' => 5,
            ]],
        ]);

        // 5 SAR per piece × 3 = 15.00, untouched by the VAT division.
        expect((float) ServiceInvoice::firstOrFail()->lines->first()->agent_commission_amount)->toBe(15.00);
    });

    it('sums the line commissions of several lines for the same agent', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [
                [
                    'branch_service_id' => $this->service->id,
                    'qty' => 3,
                    'unit_price' => 10,
                    'discount_pct' => 0,
                    'agent_id' => $this->agent->id,
                    'agent_commission_type' => 'percentage',
                    'agent_commission_value' => 10,
                ],
                [
                    'branch_service_id' => $this->service->id,
                    'qty' => 1,
                    'unit_price' => 50,
                    'discount_pct' => 0,
                    'agent_id' => $this->agent->id,
                    'agent_commission_type' => 'fixed',
                    'agent_commission_value' => 20,
                ],
            ],
        ]);

        // Percentage: 26.09 (30 net of VAT) × 10% = 2.61. Fixed: 20 × 1 = 20.00,
        // an agreed SAR figure with no VAT to strip. Total 22.61 on one pivot row.
        $invoice = ServiceInvoice::firstOrFail();
        expect($invoice->invoiceAgents()->count())->toBe(1)
            ->and((float) $invoice->invoiceAgents()->first()->line_commission_amount)->toBe(22.61);
    });

    it('leaves the employee commission and its ledger untouched', function () {
        $this->post(route('pos.service.store'), lineWithAgent([
            'agent_id' => $this->agent->id,
            'agent_commission_type' => 'percentage',
            'agent_commission_value' => 50,
        ]));

        $invoice = ServiceInvoice::firstOrFail();

        $this->actingAs($this->branchAdmin)
            ->patch(route('invoices.service.pay', payable($invoice)))
            ->assertRedirect();

        // The employee still earns their own 10% of the net-of-VAT value (2.61)
        // regardless of the commission owner's share.
        expect((float) $invoice->refresh()->employee_commission)->toBe(2.61)
            ->and((float) CommissionLedger::firstOrFail()->amount)->toBe(2.61);
    });

    it('re-syncs the line commission when the invoice is edited', function () {
        $this->post(route('pos.service.store'), lineWithAgent([
            'agent_id' => $this->agent->id,
            'agent_commission_type' => 'percentage',
            'agent_commission_value' => 10,
        ]));

        $invoice = ServiceInvoice::firstOrFail();
        expect((float) $invoice->invoiceAgents()->first()->line_commission_amount)->toBe(2.61);

        // The owner edits the line and removes the commission owner entirely.
        $this->put(route('pos.service.update', $invoice), [
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 3,
                'unit_price' => 10,
                'discount_pct' => 0,
            ]],
        ])->assertRedirect();

        expect($invoice->invoiceAgents()->count())->toBe(0)
            ->and($invoice->lines()->whereNotNull('agent_id')->count())->toBe(0);
    });
});
