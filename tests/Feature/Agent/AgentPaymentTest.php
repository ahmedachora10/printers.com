<?php

use App\Actions\InvoicePayment\RecordInvoicePaymentAction;
use App\Actions\Refund\CreateRefundAction;
use App\Enums\Roles;
use App\Models\Agent;
use App\Models\AgentPayment;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;

uses(RefreshDatabase::class);

/**
 * Create a service invoice carrying a rebate for the given agent (via the pivot).
 */
function rebateInvoice(int $branchId, int $agentId, float $rebate, array $overrides = []): ServiceInvoice
{
    $invoice = ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-'.fake()->unique()->numerify('######'),
        'branch_id' => $branchId,
        'user_id' => test()->branchAdmin->id,
        'subtotal' => 100,
        'coupon_discount' => 0,
        'agent_discount' => 0,
        'vat_pct' => 15,
        'vat_amount' => 15,
        'total_amount' => 115,
        'employee_commission' => 0,
        'status' => 'paid',
        'paid_at' => now(),
    ], $overrides));

    $invoice->invoiceAgents()->create([
        'agent_id' => $agentId,
        'discount_mode' => 'rebate',
        'discount_type' => 'percentage',
        'rate' => 10,
        'discount_amount' => 0,
        'rebate_amount' => $rebate,
    ]);
    syncAgentLedger($invoice);

    return $invoice;
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
        rebateInvoice($this->branch->id, $this->agent->id, 10);
        $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ]);

        $this->get(route('agent-payments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('agent-payments/index')
                // Payments must be a paginated resource ({ data, meta }), the
                // shape the page's TablePagination reads.
                ->has('payments.data', 1)
                ->has('payments.meta.current_page')
                ->has('payments.meta.total'));
    });

    it('lets the accountant disburse the rebate of an agent of their own branch', function () {
        // تاسك 41: المحاسب هو من يصرف — وهو ممنوع من سجلّ المندوب نفسه (تاسك 40).
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        rebateInvoice($this->branch->id, $this->agent->id, 25);

        $this->get(route('agent-payments.index'))->assertOk();

        $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        expect(AgentPayment::where('agent_id', $this->agent->id)->count())->toBe(1);

        // ولا يزال ممنوعاً من بيانات المندوب نفسها (تاسك 40) — هذا هو التبادل.
        $this->get(route('agents.index'))->assertForbidden();
    });

    it('forbids the accountant from disbursing to an agent of another branch', function () {
        $otherBranch = Branch::factory()->create();
        $accountant = User::factory()->create(['branch_id' => $otherBranch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ])->assertForbidden();
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
        expect($a->agentCommissionEntries()->value('agent_payment_id'))->toBe($paymentId)
            ->and($b->agentCommissionEntries()->value('agent_payment_id'))->toBe($paymentId);
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

    // تاسك 217: الفترة على تاريخ التحصيل لا تاريخ الإنشاء.
    it('excludes what was collected outside the chosen period', function () {
        rebateInvoice($this->branch->id, $this->agent->id, 10, ['paid_at' => now()->subMonths(2)]);

        $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('agent_id');

        expect(AgentPayment::count())->toBe(0);
    });

    it('does not pay or count the rebate of a due (unapproved) invoice', function () {
        // Only the paid invoice is payable; the due one is excluded until approved.
        rebateInvoice($this->branch->id, $this->agent->id, 10);
        rebateInvoice($this->branch->id, $this->agent->id, 20, ['status' => 'due', 'paid_at' => null]);

        $this->get(route('agent-payments.index'))
            ->assertInertia(fn ($page) => $page
                ->where('agents.0.outstandingRebate', 10)
                ->where('agents.0.outstandingInvoices', 1));

        $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ])->assertRedirect(route('agent-payments.index'));

        $this->assertDatabaseHas('agent_payments', [
            'agent_id' => $this->agent->id,
            'total_invoices' => 1,
            'total_rebate' => 10,
        ]);
    });

    it('counts per-line commissions in the outstanding balance and the payment total', function () {
        // rebate 10 plus line commissions 5 on the same pivot row.
        $invoice = rebateInvoice($this->branch->id, $this->agent->id, 10);
        $invoice->invoiceAgents()->update(['line_commission_amount' => 5]);
        syncAgentLedger($invoice);

        $this->get(route('agent-payments.index'))
            ->assertInertia(fn ($page) => $page
                ->where('agents.0.outstandingRebate', 15)
                ->where('agents.0.outstandingInvoices', 1));

        $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ])->assertRedirect(route('agent-payments.index'));

        $this->assertDatabaseHas('agent_payments', [
            'agent_id' => $this->agent->id,
            'total_invoices' => 1,
            'total_rebate' => 15,
        ]);
    });

    it('settles a pivot row carrying only line commissions (no rebate)', function () {
        $invoice = rebateInvoice($this->branch->id, $this->agent->id, 0);
        $invoice->invoiceAgents()->update(['line_commission_amount' => 8]);
        syncAgentLedger($invoice);

        $this->get(route('agent-payments.index'))
            ->assertInertia(fn ($page) => $page
                ->where('agents.0.outstandingRebate', 8)
                ->where('agents.0.outstandingInvoices', 1));

        $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ])->assertRedirect(route('agent-payments.index'));

        $this->assertDatabaseHas('agent_payments', [
            'agent_id' => $this->agent->id,
            'total_rebate' => 8,
        ]);

        expect($invoice->hasPaidAgentCommission())->toBeTrue();
    });

    it('excludes the line commission of a due invoice from the outstanding balance', function () {
        $due = rebateInvoice($this->branch->id, $this->agent->id, 0, ['status' => 'due', 'paid_at' => null]);
        $due->invoiceAgents()->update(['line_commission_amount' => 9]);
        syncAgentLedger($due);

        $this->get(route('agent-payments.index'))
            ->assertInertia(fn ($page) => $page
                ->where('agents.0.outstandingRebate', 0)
                ->where('agents.0.outstandingInvoices', 0));
    });

    it('notifies the agent when their commission is disbursed', function () {
        rebateInvoice($this->branch->id, $this->agent->id, 40);

        $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $notification = DatabaseNotification::query()
            ->where('notifiable_id', $this->agent->id)
            ->firstOrFail();

        // The pivot is polymorphic on User (Agent::getMorphClass), so the agent
        // reads the notification back as the User they log in as.
        expect($notification->notifiable_type)->toBe(User::class)
            ->and($notification->data['type'])->toBe('agent_commission_paid')
            ->and($notification->data['url'])->toBe(route('agent-portal.index'))
            ->and($notification->data['body'])
            ->toContain('40.00')
            ->toContain($this->branch->name)
            ->toContain('1 فاتورة');
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

    // ── تاسك 57: مدى تاريخي وفرز لسجل الدفعات ──────────────────────

    // تاسك 164: المدى يصفّي على الفترة المدفوع عنها (تداخل)، لا على يوم الصرف.
    it('filters the payment log by the paid-for period, not the payout date', function () {
        $pay = fn (string $start, string $end, float $total) => AgentPayment::factory()->create([
            'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'paid_by' => $this->branchAdmin->id,
            'period_start' => $start, 'period_end' => $end, 'total_rebate' => $total,
            // Both paid inside September: a paid_at filter could not tell them apart.
            'paid_at' => '2026-09-15 10:00:00',
        ]);
        $pay('2026-08-01', '2026-08-31', 100);
        $pay('2026-09-01', '2026-09-30', 40);
        $pay('2026-08-20', '2026-09-05', 7); // straddles the boundary ⇒ overlaps September

        $this->get(route('agent-payments.index', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 2)
                ->where('paymentTotals.paymentsCount', 2)
                ->where('paymentTotals.paidTotal', 47));
    });

    it('leaves the outstanding balance untouched whatever the range is', function () {
        // «العمولات المستحقة» رصيد قائم لا حركة فترة — مدى بعيد لا يُصفّره.
        rebateInvoice($this->branch->id, $this->agent->id, 25);

        $this->get(route('agent-payments.index', [
            'from' => now()->subYears(2)->toDateString(),
            'to' => now()->subYears(2)->addDay()->toDateString(),
        ]))
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 0)
                ->where('agents.0.outstandingRebate', 25)
                ->where('agents.0.outstandingInvoices', 1));
    });

    // ── تاسك 232: فلاتر «العمولات المستحقة» وتاريخ الصرف ────────────

    it('narrows the outstanding card to a period, a branch and an amount order', function () {
        $october = ['paid_at' => '2026-10-10 10:00:00'];
        $november = ['paid_at' => '2026-11-05 10:00:00'];
        rebateInvoice($this->branch->id, $this->agent->id, 30, $october);
        rebateInvoice($this->branch->id, $this->agent->id, 12, $november);
        $big = Agent::factory()->create(['branch_id' => $this->branch->id, 'name' => 'ي الأخير أبجدياً']);
        rebateInvoice($this->branch->id, $big->id, 90, $october);

        $row = fn ($page, int $agentId) => collect($page->toArray()['props']['agents'])->firstWhere('id', $agentId);

        // بلا مدى: الرصيد القائم.
        $this->get(route('agent-payments.index'))
            ->assertInertia(fn ($page) => expect($row($page, $this->agent->id)['outstandingRebate'])->toEqual(42));

        $this->get(route('agent-payments.index', ['due_from' => '2026-10-01', 'due_to' => '2026-10-31', 'due_sort' => 'amount']))
            ->assertInertia(fn ($page) => $page
                ->where('agents.0.id', $big->id)
                ->where('agents.1.outstandingRebate', 30)
                ->where('agents.1.outstandingInvoices', 1)
                ->where('filters.due_from', '2026-10-01'));

        // مدير الفرع يرسل فرعاً آخر ⇒ يبقى على فرعه.
        $this->get(route('agent-payments.index', ['due_branch' => Branch::factory()->create()->id]))
            ->assertInertia(fn ($page) => $page->has('agents', 2)->where('filters.due_branch', null));

        // ما يُعرض للفترة هو ما يُصرف لها.
        $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        ])->assertSessionHasNoErrors();
        expect((float) AgentPayment::firstOrFail()->total_rebate)->toEqual(30.0);
    });

    it('lets the super-admin narrow the outstanding card to one branch', function () {
        $superAdmin = User::factory()->create();
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);
        $other = Branch::factory()->create();
        Agent::factory()->create(['branch_id' => $other->id]);

        $this->actingAs($superAdmin)
            ->get(route('agent-payments.index', ['due_branch' => $this->branch->id]))
            ->assertInertia(fn ($page) => $page->has('agents', 1)->where('agents.0.branchId', $this->branch->id));
    });

    it('stamps the chosen payout date and refuses the future or a day before the period', function () {
        rebateInvoice($this->branch->id, $this->agent->id, 10);
        $payload = [
            'agent_id' => $this->agent->id,
            'period_start' => now()->subDays(3)->toDateString(),
            'period_end' => now()->toDateString(),
        ];

        $this->post(route('agent-payments.store'), [...$payload, 'paid_at' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('paid_at');
        $this->post(route('agent-payments.store'), [...$payload, 'paid_at' => now()->subDays(4)->toDateString()])
            ->assertSessionHasErrors('paid_at');

        $this->post(route('agent-payments.store'), [...$payload, 'paid_at' => now()->subDay()->toDateString()])
            ->assertSessionHasNoErrors();
        expect(AgentPayment::firstOrFail()->paid_at->toDateString())->toBe(now()->subDay()->toDateString());
    });

    it('filters the payment log by agent from the dropdown', function () {
        $other = Agent::factory()->create(['branch_id' => $this->branch->id, 'name' => 'مؤسسة الريان']);

        AgentPayment::factory()->create([
            'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'paid_by' => $this->branchAdmin->id, 'paid_at' => now(),
        ]);
        AgentPayment::factory()->create([
            'agent_id' => $other->id, 'branch_id' => $this->branch->id, 'paid_by' => $this->branchAdmin->id, 'paid_at' => now(),
        ]);

        $this->get(route('agent-payments.index', ['agent' => $other->id]))
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.agentName', 'مؤسسة الريان')
                ->where('paymentTotals.paymentsCount', 1)
                ->where('filters.agent', (string) $other->id));
    });

    it('sorts the payment log by total rebate on request', function () {
        AgentPayment::factory()->create([
            'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'paid_by' => $this->branchAdmin->id,
            'total_rebate' => 10, 'paid_at' => now()->subDay(),
        ]);
        AgentPayment::factory()->create([
            'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'paid_by' => $this->branchAdmin->id,
            'total_rebate' => 900, 'paid_at' => now(),
        ]);

        $this->get(route('agent-payments.index', ['sort' => 'total_rebate', 'dir' => 'asc']))
            ->assertInertia(fn ($page) => $page
                ->where('payments.data.0.totalRebate', 10)
                ->where('payments.data.1.totalRebate', 900)
                ->where('filters.sort', 'total_rebate')
                ->where('filters.dir', 'asc'));
    });

    it('defaults to the newest payment first', function () {
        AgentPayment::factory()->create([
            'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'paid_by' => $this->branchAdmin->id,
            'total_rebate' => 10, 'paid_at' => now()->subWeek(),
        ]);
        AgentPayment::factory()->create([
            'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'paid_by' => $this->branchAdmin->id,
            'total_rebate' => 900, 'paid_at' => now(),
        ]);

        $this->get(route('agent-payments.index'))
            ->assertInertia(fn ($page) => $page
                ->where('payments.data.0.totalRebate', 900)
                ->where('filters.sort', 'paid_at')
                ->where('filters.dir', 'desc'));
    });

    it('rejects an unknown sort column', function () {
        $this->get(route('agent-payments.index', ['sort' => 'total_rebate; drop table users']))
            ->assertSessionHasErrors('sort');
    });

    // تاسك 217 — عمولة المندوب بتاريخ السداد الفعلي، والجزئية بقدر كل دفعة.
    it('dates the agent commission by the payment that settled the invoice, not its creation', function () {
        $this->travelTo('2026-10-15 10:00');
        $invoice = rebateInvoice($this->branch->id, $this->agent->id, 20, ['status' => 'due', 'paid_at' => null]);

        $this->travelTo('2026-11-05 10:00');
        app(RecordInvoicePaymentAction::class)->handle($invoice, ['amount' => 115], $this->branchAdmin);

        $pay = fn (string $from, string $to) => $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id, 'period_start' => $from, 'period_end' => $to,
        ]);
        $pay('2026-10-01', '2026-10-31')->assertSessionHasErrors('agent_id');
        $pay('2026-11-01', '2026-11-30')->assertSessionHasNoErrors();

        expect((float) AgentPayment::sole()->total_rebate)->toBe(20.0);
    });

    it('earns the agent commission payment by payment, each in its own month, and pays it once', function () {
        $this->travelTo('2026-10-15 10:00');
        $invoice = rebateInvoice($this->branch->id, $this->agent->id, 20, ['status' => 'due', 'paid_at' => null]);
        $this->travelTo('2026-10-20 10:00');
        app(RecordInvoicePaymentAction::class)->handle($invoice, ['amount' => 46], $this->branchAdmin); // 40%
        $this->travelTo('2026-11-10 10:00');
        app(RecordInvoicePaymentAction::class)->handle($invoice->refresh(), ['amount' => 69], $this->branchAdmin);

        $pay = fn (string $from, string $to) => $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id, 'period_start' => $from, 'period_end' => $to,
        ]);
        $pay('2026-10-01', '2026-10-31')->assertSessionHasNoErrors();
        $pay('2026-11-01', '2026-11-30')->assertSessionHasNoErrors();
        $pay('2026-11-01', '2026-11-30')->assertSessionHasErrors('agent_id');

        expect(AgentPayment::orderBy('id')->pluck('total_rebate')->map(fn ($v) => (float) $v)->all())->toBe([8.0, 12.0])
            ->and($invoice->refresh()->status->value)->toBe('paid');
    });

    it('keeps a paid-out agent commission when a later refund lowers what was collected', function () {
        $this->travelTo('2026-10-20 10:00');
        $invoice = rebateInvoice($this->branch->id, $this->agent->id, 20, ['status' => 'due', 'paid_at' => null]);
        app(RecordInvoicePaymentAction::class)->handle($invoice, ['amount' => 46], $this->branchAdmin);
        $this->post(route('agent-payments.store'), [
            'agent_id' => $this->agent->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        ])->assertSessionHasNoErrors();

        app(CreateRefundAction::class)->handle([
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'amount' => 46,
            'reason' => 'استرجاع',
            'payment_method_id' => paymentMethodId($this->branch->id),
        ], $this->branchAdmin);

        expect((float) $invoice->agentCommissionEntries()->sum('rebate_amount'))->toBe(8.0);
    });

    it('earns a product-invoice agent rebate on the payment date, in proportion', function () {
        $this->travelTo('2026-10-15 10:00');
        $invoice = ProductInvoice::create([
            'invoice_number' => 'INV-217-1',
            'branch_id' => $this->branch->id,
            'user_id' => $this->branchAdmin->id,
            'agent_id' => $this->agent->id,
            'agent_rebate' => 10,
            'subtotal' => 100,
            'vat_pct' => 15,
            'vat_amount' => 15,
            'total_amount' => 115,
            'status' => 'due',
        ]);

        $this->travelTo('2026-11-05 10:00');
        app(RecordInvoicePaymentAction::class)->handle($invoice, ['amount' => 57.5], $this->branchAdmin);

        $entry = $invoice->agentCommissionEntries()->sole();
        expect((float) $entry->rebate_amount)->toBe(5.0)
            ->and((float) $entry->sales_amount)->toBe(57.5)
            ->and($entry->earned_at->toDateString())->toBe('2026-11-05');
    });

    it('rejects a range that ends before it starts', function () {
        $this->get(route('agent-payments.index', [
            'from' => now()->toDateString(),
            'to' => now()->subWeek()->toDateString(),
        ]))->assertSessionHasErrors('to');
    });
});
