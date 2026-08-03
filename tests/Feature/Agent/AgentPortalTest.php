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
    $rebate = $attrs['agent_rebate'] ?? 0;
    $discount = $attrs['agent_discount'] ?? 0;
    $paymentId = $attrs['agent_payment_id'] ?? null;
    unset($attrs['agent_rebate'], $attrs['agent_discount'], $attrs['agent_payment_id'], $attrs['agent_id']);

    $base = [
        'invoice_number' => fake()->unique()->numerify('INV-######'),
        'branch_id' => $branchId,
        'user_id' => test()->branchAdmin->id,
        'subtotal' => 100,
        'coupon_discount' => 0,
        'agent_discount' => $discount,
        'vat_pct' => 15,
        'vat_amount' => 15,
        'total_amount' => 115,
        'status' => 'paid',
        'paid_at' => now(),
    ];

    // Service invoices carry their agents on the pivot; product invoices keep the
    // single agent columns on the invoice row.
    if ($model === ServiceInvoice::class) {
        $base['employee_commission'] = 0;
        $invoice = ServiceInvoice::create(array_merge($base, $attrs));
        $invoice->invoiceAgents()->create([
            'agent_id' => $agentId,
            'discount_mode' => 'rebate',
            'discount_type' => 'percentage',
            'rate' => 10,
            'discount_amount' => $discount,
            'rebate_amount' => $rebate,
            'agent_payment_id' => $paymentId,
        ]);

        return;
    }

    ProductInvoice::create(array_merge($base, [
        'agent_id' => $agentId,
        'agent_rebate' => $rebate,
        'agent_payment_id' => $paymentId,
    ], $attrs));
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

    it('hides a due (unapproved) invoice and its rebate from the agent', function () {
        // A paid invoice is visible; a due one must stay hidden until approved.
        agentInvoice(ServiceInvoice::class, $this->branch->id, $this->agent->id, ['agent_rebate' => 10]);
        agentInvoice(ServiceInvoice::class, $this->branch->id, $this->agent->id, [
            'agent_rebate' => 20,
            'status' => 'due',
            'paid_at' => null,
        ]);

        $this->actingAs($this->agent);

        $this->get(route('agent-portal.index'))
            ->assertInertia(fn ($page) => $page
                ->where('summary.invoiceCount', 1)
                ->where('summary.rebateEarned', 10)
                ->where('summary.rebateOutstanding', 10)
                ->has('recentInvoices', 1));
    });

    it('opens on today and hides an invoice from an earlier day', function () {
        agentInvoice(ServiceInvoice::class, $this->branch->id, $this->agent->id, ['agent_rebate' => 10]);

        agentInvoice(ServiceInvoice::class, $this->branch->id, $this->agent->id, ['agent_rebate' => 40]);
        // created_at is not fillable — force it to backdate the invoice.
        ServiceInvoice::latest('id')->firstOrFail()->forceFill(['created_at' => now()->subDays(5)])->save();

        $this->actingAs($this->agent);

        $this->get(route('agent-portal.index'))
            ->assertInertia(fn ($page) => $page
                ->where('summary.invoiceCount', 1)
                ->where('summary.rebateEarned', 10)
                ->where('filters.from', now()->toDateString())
                ->where('filters.to', now()->toDateString())
                ->has('recentInvoices', 1));

        // Widening the range brings the older invoice back.
        $this->get(route('agent-portal.index', ['from' => now()->subDays(7)->toDateString(), 'to' => now()->toDateString()]))
            ->assertInertia(fn ($page) => $page
                ->where('summary.invoiceCount', 2)
                ->where('summary.rebateEarned', 50)
                ->has('recentInvoices', 2));
    });

    it('names the employee who raised the invoice and the service sold', function () {
        agentInvoice(ServiceInvoice::class, $this->branch->id, $this->agent->id, ['agent_rebate' => 10]);

        $invoice = ServiceInvoice::latest('id')->firstOrFail();
        $invoice->lines()->createMany([
            ['service_name' => 'طباعة لوحة', 'qty' => 1, 'unit_price' => 60, 'discount_pct' => 0, 'subtotal' => 60, 'commission_pct' => 0, 'commission_amount' => 0],
            ['service_name' => 'تغليف', 'qty' => 1, 'unit_price' => 40, 'discount_pct' => 0, 'subtotal' => 40, 'commission_pct' => 0, 'commission_amount' => 0],
        ]);

        $this->actingAs($this->agent);

        $this->get(route('agent-portal.index'))
            ->assertInertia(fn ($page) => $page
                ->where('recentInvoices.0.employeeName', $this->branchAdmin->name)
                ->where('recentInvoices.0.itemsLabel', 'طباعة لوحة و1 أخرى'));
    });

    it('redirects an agent to the portal on login', function () {
        $this->agent->update(['username' => 'agent_login']);

        $this->post(route('login'), [
            'username' => 'agent_login',
            'password' => 'password',
        ])->assertRedirect(route('agent-portal.index'));
    });
});
