<?php

use App\Enums\Roles;
use App\Models\Agent;
use App\Models\AgentPayment;
use App\Models\Branch;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create a service invoice carrying a rebate for the given agent.
 */
function rebateInvoice(int $branchId, int $agentId, float $rebate, array $overrides = []): ServiceInvoice
{
    return ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-'.fake()->unique()->numerify('######'),
        'branch_id' => $branchId,
        'user_id' => test()->branchAdmin->id,
        'agent_id' => $agentId,
        'subtotal' => 100,
        'coupon_discount' => 0,
        'agent_discount' => 0,
        'agent_rebate' => $rebate,
        'vat_pct' => 15,
        'vat_amount' => 15,
        'total_amount' => 115,
        'employee_commission' => 0,
        'status' => 'paid',
        'paid_at' => now(),
    ], $overrides));
}

describe('Agent Payments', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);
        $this->actingAs($this->branchAdmin);

        $this->agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
    });

    it('allows branch-admin to view the payments page', function () {
        $this->get(route('agent-payments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('agent-payments/index'));
    });

    it('prevents accountant from viewing the payments page', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        $this->get(route('agent-payments.index'))->assertForbidden();
    });

    it('reports the outstanding rebate per agent', function () {
        rebateInvoice($this->branch->id, $this->agent->id, 10);
        rebateInvoice($this->branch->id, $this->agent->id, 15);

        $this->get(route('agent-payments.index'))
            ->assertInertia(fn ($page) => $page
                ->where('agents.0.id', $this->agent->id)
                ->where('agents.0.outstandingRebate', 25)
                ->where('agents.0.outstandingInvoices', 2));
    });

    it('generates a payment summing unpaid rebates in the period and marks the invoices', function () {
        $a = rebateInvoice($this->branch->id, $this->agent->id, 10);
        $b = rebateInvoice($this->branch->id, $this->agent->id, 15);

        $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ])->assertRedirect(route('agent-payments.index'));

        $this->assertDatabaseHas('agent_payments', [
            'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id,
            'total_invoices' => 2,
            'total_rebate' => 25,
            'paid_by' => $this->branchAdmin->id,
        ]);

        $paymentId = AgentPayment::firstOrFail()->id;
        expect($a->fresh()->agent_payment_id)->toBe($paymentId)
            ->and($b->fresh()->agent_payment_id)->toBe($paymentId);
    });

    it('does not pay the same rebate twice', function () {
        rebateInvoice($this->branch->id, $this->agent->id, 10);

        $payload = [
            'agent_id' => $this->agent->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ];

        $this->post(route('agent-payments.store'), $payload)->assertRedirect();

        // Second run over the same period: nothing left to pay.
        $this->post(route('agent-payments.store'), $payload)->assertSessionHasErrors('agent_id');

        expect(AgentPayment::count())->toBe(1);
    });

    it('excludes invoices outside the chosen period', function () {
        $old = rebateInvoice($this->branch->id, $this->agent->id, 10);
        // created_at is managed on insert; force it into the past for this check.
        ServiceInvoice::where('id', $old->id)->update(['created_at' => now()->subMonths(2)]);

        $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('agent_id');

        expect(AgentPayment::count())->toBe(0);
    });

    it('prevents a branch-admin from paying an agent in another branch', function () {
        $otherBranch = Branch::factory()->create();
        $otherAgent = Agent::factory()->create(['branch_id' => $otherBranch->id]);
        rebateInvoice($otherBranch->id, $otherAgent->id, 10);

        $this->post(route('agent-payments.store'), [
            'agent_id' => $otherAgent->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ])->assertForbidden();

        expect(AgentPayment::count())->toBe(0);
    });
});
