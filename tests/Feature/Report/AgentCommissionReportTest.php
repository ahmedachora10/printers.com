<?php

use App\Enums\Roles;
use App\Models\Agent;
use App\Models\AgentPayment;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A paid service invoice with one agent attached through the pivot, mirroring
 * what the POS writes.
 */
function agentServiceInvoice(Branch $branch, User $employee, Agent $agent, array $pivot = [], array $overrides = []): ServiceInvoice
{
    $invoice = ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-AC-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branch->id,
        'user_id' => $employee->id,
        'subtotal' => 200,
        'vat_pct' => 15,
        'vat_amount' => 30,
        'total_amount' => 230,
        'employee_commission' => 20,
        'status' => 'paid',
        'paid_at' => now(),
    ], $overrides));

    ServiceInvoiceLine::create([
        'invoice_id' => $invoice->id,
        'service_name' => 'طباعة لوحة',
        'qty' => 1,
        'unit_price' => 200,
        'discount_pct' => 0,
        'subtotal' => 200,
        'commission_pct' => 10,
        'commission_amount' => 20,
    ]);

    $invoice->invoiceAgents()->create(array_merge([
        'agent_id' => $agent->id,
        'discount_mode' => 'rebate',
        'discount_type' => 'percentage',
        'rate' => 10,
        'discount_amount' => 0,
        'rebate_amount' => 20,
        'line_commission_amount' => 0,
    ], $pivot));

    return $invoice;
}

describe('Agent Commission Report', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'موظف الفرع']);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->agent = Agent::factory()->create(['branch_id' => $this->branch->id, 'name' => 'مندوب أول']);
    });

    // ── ACCESS ─────────────────────────────────────────────────────

    it('lets an accountant open the report for their branch', function () {
        $this->actingAs($this->accountant)
            ->get(route('reports.agent-commissions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('reports/agent-commissions/index'));
    });

    it('lets a branch-admin and a super-admin open the report', function () {
        $this->actingAs($this->branchAdmin)->get(route('reports.agent-commissions'))->assertOk();
        $this->actingAs($this->superAdmin)->get(route('reports.agent-commissions'))->assertOk();
    });

    it('forbids an employee from the report', function () {
        $this->actingAs($this->employee)
            ->get(route('reports.agent-commissions'))
            ->assertForbidden();
    });

    it('forbids an agent from the report', function () {
        $this->actingAs($this->agent)
            ->get(route('reports.agent-commissions'))
            ->assertForbidden();
    });

    // ── AMOUNTS ────────────────────────────────────────────────────

    it('totals rebate and line commissions per agent', function () {
        agentServiceInvoice($this->branch, $this->employee, $this->agent, ['rebate_amount' => 20, 'line_commission_amount' => 5]);
        agentServiceInvoice($this->branch, $this->employee, $this->agent, ['rebate_amount' => 10, 'line_commission_amount' => 2.5]);

        $this->actingAs($this->accountant)
            ->get(route('reports.agent-commissions'))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.agentName', 'مندوب أول')
                ->where('rows.0.invoiceCount', 2)
                ->where('rows.0.rebate', 30)
                ->where('rows.0.lineCommission', 7.5)
                ->where('rows.0.due', 37.5)
                ->where('rows.0.paid', 0)
                ->where('rows.0.outstanding', 37.5)
                ->where('totals.due', 37.5));
    });

    it('counts a product invoice rebate alongside the service ones', function () {
        agentServiceInvoice($this->branch, $this->employee, $this->agent, ['rebate_amount' => 20]);

        ProductInvoice::create([
            'invoice_number' => 'INV-AC-1',
            'branch_id' => $this->branch->id,
            'user_id' => $this->accountant->id,
            'agent_id' => $this->agent->id,
            'subtotal' => 100,
            'vat_pct' => 15,
            'vat_amount' => 15,
            'total_amount' => 115,
            'agent_rebate' => 11,
            'agent_discount' => 4,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->actingAs($this->accountant)
            ->get(route('reports.agent-commissions'))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.invoiceCount', 2)
                ->where('rows.0.rebate', 31)
                ->where('rows.0.discount', 4)
                ->where('totals.due', 31));
    });

    it('counts only approved invoices', function () {
        agentServiceInvoice($this->branch, $this->employee, $this->agent, ['rebate_amount' => 20]);
        agentServiceInvoice(
            $this->branch,
            $this->employee,
            $this->agent,
            ['rebate_amount' => 99],
            ['status' => 'due', 'paid_at' => null],
        );

        $this->actingAs($this->accountant)
            ->get(route('reports.agent-commissions'))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.invoiceCount', 1)
                ->where('totals.due', 20));
    });

    it('splits settled from outstanding using the payment stamp', function () {
        $payment = AgentPayment::create([
            'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'total_invoices' => 1,
            'total_rebate' => 20,
            'paid_by' => $this->branchAdmin->id,
            'paid_at' => now(),
        ]);

        agentServiceInvoice($this->branch, $this->employee, $this->agent, ['rebate_amount' => 20, 'agent_payment_id' => $payment->id]);
        agentServiceInvoice($this->branch, $this->employee, $this->agent, ['rebate_amount' => 15]);

        $this->actingAs($this->accountant)
            ->get(route('reports.agent-commissions'))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.due', 35)
                ->where('rows.0.paid', 20)
                ->where('rows.0.outstanding', 15));
    });

    // ── SCOPE ──────────────────────────────────────────────────────

    it('scopes an accountant to their own branch', function () {
        agentServiceInvoice($this->branch, $this->employee, $this->agent, ['rebate_amount' => 20]);

        $otherBranch = Branch::factory()->create();
        $otherEmployee = User::factory()->create(['branch_id' => $otherBranch->id]);
        $otherEmployee->addRole(Roles::EMPLOYEE->value);
        $otherAgent = Agent::factory()->create(['branch_id' => $otherBranch->id]);
        agentServiceInvoice($otherBranch, $otherEmployee, $otherAgent, ['rebate_amount' => 77]);

        $this->actingAs($this->accountant)
            ->get(route('reports.agent-commissions'))
            ->assertInertia(fn ($page) => $page->has('rows', 1)->where('totals.due', 20));

        // The super-admin sees both branches.
        $this->actingAs($this->superAdmin)
            ->get(route('reports.agent-commissions'))
            ->assertInertia(fn ($page) => $page->has('rows', 2)->where('totals.due', 97));
    });

    it('opens on today and widens with the range', function () {
        agentServiceInvoice($this->branch, $this->employee, $this->agent, ['rebate_amount' => 20]);

        $old = agentServiceInvoice($this->branch, $this->employee, $this->agent, ['rebate_amount' => 40]);
        $old->forceFill(['created_at' => now()->subDays(4)])->save();

        $this->actingAs($this->accountant)
            ->get(route('reports.agent-commissions'))
            ->assertInertia(fn ($page) => $page->where('totals.due', 20)->where('filters.from', now()->toDateString()));

        $this->actingAs($this->accountant)
            ->get(route('reports.agent-commissions', ['from' => now()->subDays(7)->toDateString(), 'to' => now()->toDateString()]))
            ->assertInertia(fn ($page) => $page->where('totals.due', 60));
    });

    // ── DRILL-DOWN & EXPORT ────────────────────────────────────────

    it('names the employee and the service on each drill-down row', function () {
        agentServiceInvoice($this->branch, $this->employee, $this->agent, ['rebate_amount' => 20]);

        $this->actingAs($this->accountant)
            ->get(route('reports.agent-commissions'))
            ->assertInertia(fn ($page) => $page
                ->has('lines', 1)
                ->where('lines.0.employeeName', 'موظف الفرع')
                ->where('lines.0.itemsLabel', 'طباعة لوحة')
                ->where('lines.0.amount', 20)
                ->where('lines.0.isPaid', false));
    });

    it('exports the report as an xlsx download', function () {
        agentServiceInvoice($this->branch, $this->employee, $this->agent, ['rebate_amount' => 20]);

        $response = $this->actingAs($this->accountant)->get(route('reports.agent-commissions.export'));

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('.xlsx');
    });
});
