<?php

use App\Enums\Roles;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function agentInvoice(string $model, int $branchId, int $agentId, array $attrs): void
{
    $base = [
        'invoice_number' => fake()->unique()->numerify('INV-######'),
        'branch_id' => $branchId,
        'user_id' => test()->branchAdmin->id,
        'agent_id' => $agentId,
        'subtotal' => 100,
        'coupon_discount' => 0,
        'agent_discount' => 0,
        'agent_rebate' => 0,
        'vat_pct' => 15,
        'vat_amount' => 15,
        'total_amount' => 115,
        'status' => 'paid',
        'paid_at' => now(),
    ];

    if ($model === ServiceInvoice::class) {
        $base['employee_commission'] = 0;
    }

    $model::create(array_merge($base, $attrs));
}

describe('Agent Portal', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        $this->agent->agentProfile->update(['discount_mode' => 'rebate', 'rate' => 10]);
    });

    it('lets an agent open their portal', function () {
        $this->actingAs($this->agent);

        $this->get(route('agent-portal.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('agent-portal/index'));
    });

    it('forbids non-agents from the portal', function () {
        $this->actingAs($this->branchAdmin);
        $this->get(route('agent-portal.index'))->assertForbidden();
    });

    it('forbids an agent from the admin agent pages', function () {
        $this->actingAs($this->agent);
        $this->get(route('agents.index'))->assertForbidden();
        $this->get(route('agent-payments.index'))->assertForbidden();
    });

    it('summarizes only the signed-in agent rebates across both invoice types', function () {
        agentInvoice(ServiceInvoice::class, $this->branch->id, $this->agent->id, ['agent_rebate' => 10]);
        agentInvoice(ProductInvoice::class, $this->branch->id, $this->agent->id, ['agent_rebate' => 15, 'agent_payment_id' => null]);

        // Another agent's invoice — must not leak into this agent's totals.
        $other = Agent::factory()->create(['branch_id' => $this->branch->id]);
        agentInvoice(ServiceInvoice::class, $this->branch->id, $other->id, ['agent_rebate' => 99]);

        $this->actingAs($this->agent);

        $this->get(route('agent-portal.index'))
            ->assertInertia(fn ($page) => $page
                ->where('summary.invoiceCount', 2)
                ->where('summary.rebateEarned', 25)
                ->where('summary.rebatePaid', 0)
                ->where('summary.rebateOutstanding', 25)
                ->has('recentInvoices', 2));
    });

    it('redirects an agent to the portal on login', function () {
        $this->agent->update(['username' => 'agent_login']);

        $this->post(route('login'), [
            'username' => 'agent_login',
            'password' => 'password',
        ])->assertRedirect(route('agent-portal.index'));
    });
});
