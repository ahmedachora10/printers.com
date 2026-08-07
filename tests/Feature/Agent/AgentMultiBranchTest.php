<?php

use App\Actions\Agent\GenerateAgentPaymentAction;
use App\Enums\Roles;
use App\Models\Agent;
use App\Models\AgentPayment;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * تاسك 20-د — ربط المندوب بأكثر من فرع.
 *
 * One مندوب, several branches, different terms in each. The link table decides
 * where the agent is available and on what terms; each branch settles its own
 * invoices.
 */

/** A branch with an employee who can raise service invoices in it. */
function multiBranch(string $name): array
{
    $branch = Branch::factory()->create(['name' => $name, 'vat_rate_override' => 15.00]);

    $employee = User::factory()->create(['branch_id' => $branch->id]);
    $employee->addRole(Roles::EMPLOYEE->value);

    $template = ServiceTemplate::factory()->create();
    BranchService::create([
        'branch_id' => $branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'is_tahazir' => false,
        'is_active' => true,
    ]);

    $service = BranchService::where('branch_id', $branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();

    UserService::create([
        'user_id' => $employee->id,
        'branch_service_id' => $service->id,
        'commission_override_pct' => 10,
    ]);

    return ['branch' => $branch, 'employee' => $employee, 'service' => $service];
}

/** A branch admin owning the given branch. */
function branchAdminOf(Branch $branch): User
{
    $admin = User::factory()->create();
    $admin->addRole(Roles::BRANCH_ADMIN->value);
    $branch->update(['owner_id' => $admin->id]);

    return $admin;
}

/** Raise a paid service invoice in one branch naming the agent. */
function paidServiceInvoiceFor(array $site, Agent $agent): ServiceInvoice
{
    test()->actingAs($site['employee'])
        ->post(route('pos.service.store'), [
            'status' => 'due',
            'agent_ids' => [$agent->id],
            'lines' => [
                ['branch_service_id' => $site['service']->id, 'qty' => 3, 'unit_price' => 10, 'discount_pct' => 0],
            ],
        ])->assertSessionHasNoErrors();

    $invoice = ServiceInvoice::where('branch_id', $site['branch']->id)->latest('id')->firstOrFail();

    // Only an approved invoice is payable, so approve it as the branch's admin.
    test()->actingAs(branchAdminOf($site['branch']))
        ->patch(route('invoices.service.pay', $invoice))
        ->assertRedirect();

    return $invoice->refresh();
}

describe('Agent multi-branch (تاسك 20-د)', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->a = multiBranch('فرع أ');
        $this->b = multiBranch('فرع ب');

        // One agent, both branches, different rebate rates.
        $this->agent = Agent::factory()->create(['branch_id' => $this->a['branch']->id]);
        setAgentBranchTerms($this->agent, $this->a['branch']->id, ['discount_mode' => 'rebate', 'rate' => 10]);
        setAgentBranchTerms($this->agent, $this->b['branch']->id, ['discount_mode' => 'rebate', 'rate' => 5]);
    });

    it('applies each branch its own rate on the same agent', function () {
        $inA = paidServiceInvoiceFor($this->a, $this->agent);
        $inB = paidServiceInvoiceFor($this->b, $this->agent);

        // subtotal 30, net of 15% VAT = 26.09 — rebate 10% in أ, 5% in ب.
        expect((float) $inA->invoiceAgents()->value('rebate_amount'))->toBe(2.61)
            ->and((float) $inB->invoiceAgents()->value('rebate_amount'))->toBe(1.30);
    });

    it('offers the agent in the POS of every linked branch', function () {
        foreach ([$this->a, $this->b] as $site) {
            $this->actingAs($site['employee'])
                ->get(route('pos.service.create'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->has('agents', 1)
                    ->where('agents.0.id', $this->agent->id));
        }
    });

    it('hides the agent from a branch it is not linked to and rejects it on submit', function () {
        $c = multiBranch('فرع ج');

        $this->actingAs($c['employee'])
            ->get(route('pos.service.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('agents', 0));

        $this->actingAs($c['employee'])
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'agent_ids' => [$this->agent->id],
                'lines' => [
                    ['branch_service_id' => $c['service']->id, 'qty' => 3, 'unit_price' => 10, 'discount_pct' => 0],
                ],
            ])
            ->assertSessionHasErrors('agent_ids');

        expect(ServiceInvoice::where('branch_id', $c['branch']->id)->count())->toBe(0);
    });

    it('settles only the paid branch and leaves the other branch outstanding', function () {
        $inA = paidServiceInvoiceFor($this->a, $this->agent);
        $inB = paidServiceInvoiceFor($this->b, $this->agent);

        $this->actingAs(branchAdminOf($this->a['branch']));

        app(GenerateAgentPaymentAction::class)->handle([
            'agent_id' => $this->agent->id,
            'branch_id' => $this->a['branch']->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ]);

        $payment = AgentPayment::firstOrFail();

        expect($payment->branch_id)->toBe($this->a['branch']->id)
            ->and($payment->total_invoices)->toBe(1)
            ->and((float) $payment->total_rebate)->toBe(2.61)
            // فرع ب remains unsettled: its invoice was never in this run.
            ->and($inA->invoiceAgents()->value('agent_payment_id'))->not->toBeNull()
            ->and($inB->invoiceAgents()->value('agent_payment_id'))->toBeNull();
    });

    it('also scopes product-invoice rebates to the branch being settled', function () {
        foreach ([$this->a, $this->b] as $site) {
            ProductInvoice::create([
                'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
                'branch_id' => $site['branch']->id,
                'user_id' => $site['employee']->id,
                'agent_id' => $this->agent->id,
                'subtotal' => 100,
                'coupon_discount' => 0,
                'agent_discount' => 0,
                'agent_rebate' => 8,
                'vat_pct' => 15,
                'vat_amount' => 15,
                'total_amount' => 115,
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }

        $this->actingAs(branchAdminOf($this->b['branch']));

        $payment = app(GenerateAgentPaymentAction::class)->handle([
            'agent_id' => $this->agent->id,
            'branch_id' => $this->b['branch']->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ]);

        expect($payment->total_invoices)->toBe(1)
            ->and((float) $payment->total_rebate)->toBe(8.0)
            ->and(ProductInvoice::where('branch_id', $this->a['branch']->id)->value('agent_payment_id'))->toBeNull();
    });

    it('lists one settlement row per agent and branch for a super-admin', function () {
        paidServiceInvoiceFor($this->a, $this->agent);
        paidServiceInvoiceFor($this->b, $this->agent);

        $superAdmin = User::factory()->create();
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->actingAs($superAdmin)
            ->get(route('agent-payments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('agents', 2)
                ->where('agents.0.outstandingRebate', 2.61)
                ->where('agents.1.outstandingRebate', 1.3));
    });

    it('rejects a settlement for a branch the agent is not linked to', function () {
        $c = multiBranch('فرع ج');
        $superAdmin = User::factory()->create();
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->actingAs($superAdmin)
            ->post(route('agent-payments.store'), [
                'agent_id' => $this->agent->id,
                'branch_id' => $c['branch']->id,
                'period_start' => now()->subDay()->toDateString(),
                'period_end' => now()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('branch_id');
    });

    it('lets the admin of any linked branch manage the agent', function () {
        // فرع ب is not the agent's primary branch, but it is a linked one.
        $this->actingAs(branchAdminOf($this->b['branch']))
            ->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('items.data', 1));
    });

    it('keeps an unlinked branch admin away from the agent', function () {
        $c = multiBranch('فرع ج');

        $this->actingAs(branchAdminOf($c['branch']))
            ->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('items.data', 0));
    });

    it('does not let a branch admin detach the branches they cannot see', function () {
        $admin = branchAdminOf($this->b['branch']);

        $this->actingAs($admin)
            ->put(route('agents.update', $this->agent), [
                'name' => 'مندوب معدَّل',
                'username' => $this->agent->username,
                'email' => $this->agent->email,
                'agent_type' => 'individual',
                'discount_mode' => 'rebate',
                'discount_type' => 'percentage',
                'rate' => 7,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->agent->refresh();

        // Their edit rewrote فرع ب's rate only; فرع أ kept its 10% and its link.
        expect($this->agent->agentBranches()->count())->toBe(2)
            ->and((float) $this->agent->termsForBranch($this->b['branch']->id)->rate)->toBe(7.0)
            ->and((float) $this->agent->termsForBranch($this->a['branch']->id)->rate)->toBe(10.0);
    });

    it('does not let a branch admin move the agent primary branch to their own', function () {
        $this->actingAs(branchAdminOf($this->b['branch']))
            ->put(route('agents.update', $this->agent), [
                'name' => $this->agent->name,
                'username' => $this->agent->username,
                'email' => $this->agent->email,
                'branch_id' => $this->b['branch']->id,
                'agent_type' => 'individual',
                'discount_mode' => 'rebate',
                'discount_type' => 'percentage',
                'rate' => 5,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        expect($this->agent->refresh()->branch_id)->toBe($this->a['branch']->id);
    });

    it('lets a super-admin link several branches at once with their own terms', function () {
        $superAdmin = User::factory()->create();
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->actingAs($superAdmin)
            ->post(route('agents.store'), [
                'name' => 'مندوب جديد',
                'username' => 'new.agent',
                'email' => 'new.agent@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'agent_type' => 'individual',
                'is_active' => true,
                'branches' => [
                    ['branch_id' => $this->a['branch']->id, 'discount_mode' => 'rebate', 'discount_type' => 'percentage', 'rate' => 12],
                    ['branch_id' => $this->b['branch']->id, 'discount_mode' => 'discount', 'discount_type' => 'fixed', 'rate' => 20],
                ],
            ])
            ->assertSessionHasNoErrors();

        $created = Agent::where('username', 'new.agent')->firstOrFail();
        $inA = $created->termsForBranch($this->a['branch']->id);
        $inB = $created->termsForBranch($this->b['branch']->id);

        expect((float) $inA->rate)->toBe(12.0)
            ->and($inA->discount_mode->value)->toBe('rebate')
            ->and((float) $inB->rate)->toBe(20.0)
            ->and($inB->discount_mode->value)->toBe('discount')
            ->and($inB->discount_type->value)->toBe('fixed')
            // The primary branch defaults to the first link.
            ->and($created->branch_id)->toBe($this->a['branch']->id);
    });

    it('merges both branches in the agent portal and narrows to one on request', function () {
        paidServiceInvoiceFor($this->a, $this->agent);
        paidServiceInvoiceFor($this->b, $this->agent);

        $this->actingAs($this->agent);

        $this->get(route('agent-portal.index', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('agent.branches', 2)
                ->has('recentInvoices', 2)
                ->where('summary.rebateEarned', 3.91));

        $this->get(route('agent-portal.index', [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
            'branch' => $this->b['branch']->id,
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('recentInvoices', 1)
                ->where('summary.rebateEarned', 1.3));
    });

    it('shows a branch admin only their own branch share in the agent report', function () {
        paidServiceInvoiceFor($this->a, $this->agent);
        paidServiceInvoiceFor($this->b, $this->agent);

        $this->actingAs(branchAdminOf($this->a['branch']))
            ->get(route('reports.agent-commissions', [
                'from' => now()->subDay()->toDateString(),
                'to' => now()->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.rebate', 2.61)
                ->where('totals.invoiceCount', 1));
    });
});
