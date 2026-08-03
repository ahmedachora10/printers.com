<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create a commission ledger entry backed by a real service invoice line so the
 * report's drill-down join resolves it.
 */
function ledgerLine(User $user, Branch $branch, array $ledger = [], array $lineOverrides = []): CommissionLedger
{
    $invoice = ServiceInvoice::create([
        'invoice_number' => 'SINV-TST-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 15,
        'total_amount' => 115,
        'employee_commission' => 10,
        'status' => 'paid',
        // A paid invoice always carries paid_at in the real flow, and the
        // للعمولات column reads the range off it.
        'paid_at' => now(),
    ]);

    $line = ServiceInvoiceLine::create(array_merge([
        'invoice_id' => $invoice->id,
        'service_name' => 'طباعة',
        'qty' => 1,
        'unit_price' => 100,
        'discount_pct' => 0,
        'subtotal' => 100,
        'commission_pct' => 10,
        'commission_amount' => 10,
    ], $lineOverrides));

    return CommissionLedger::factory()->create(array_merge([
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'invoice_line_id' => $line->id,
        'invoice_line_type' => ServiceInvoiceLine::class,
    ], $ledger));
}

describe('Employee Commission Report', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->otherBranch = Branch::factory()->create();
        $this->otherEmployee = User::factory()->create(['branch_id' => $this->otherBranch->id]);
        $this->otherEmployee->addRole(Roles::EMPLOYEE->value);
    });

    // ── ACCESS ─────────────────────────────────────────────────────

    it('lets a super-admin view the report', function () {
        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/commissions/index')
                ->has('summary')
                ->has('lines')
                ->has('totals')
                ->where('isSuperAdmin', true));
    });

    it('lets an employee view the report', function () {
        $this->actingAs($this->employee)
            ->get(route('reports.commissions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('isSuperAdmin', false));
    });

    it('forbids an agent from viewing the report', function () {
        $agent = User::factory()->create();
        $agent->addRole(Roles::AGENT->value);

        $this->actingAs($agent)
            ->get(route('reports.commissions'))
            ->assertForbidden();
    });

    // ── AGGREGATION ────────────────────────────────────────────────

    it('aggregates earned, paid, pending and tahazir per employee', function () {
        ledgerLine($this->employee, $this->branch, ['amount' => 100, 'is_tahazir' => false, 'paid_at' => now()]);
        ledgerLine($this->employee, $this->branch, ['amount' => 40, 'is_tahazir' => true, 'paid_at' => null]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.earned', 140)
                ->where('totals.paid', 100)
                ->where('totals.pending', 40)
                ->where('totals.tahazir', 40)
                ->where('summary.0.pending', 40)
                ->where('summary.0.lineCount', 2));
    });

    it('returns line-level detail resolved to the invoice', function () {
        ledgerLine($this->employee, $this->branch, ['amount' => 55], ['service_name' => 'تصميم شعار']);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.lineCount', 1)
                ->where('lines.0.serviceName', 'تصميم شعار')
                ->where('lines.0.amount', 55)
                ->where('lines.0.invoiceStatus', 'paid')
                ->has('lines.0.invoiceNumber'));
    });

    it('surfaces the invoice approval status on each detail line', function () {
        ledgerLine($this->employee, $this->branch, ['amount' => 20]);
        $cancelled = ledgerLine($this->employee, $this->branch, ['amount' => 30]);

        // Flip the second line's invoice to cancelled to prove the column tracks
        // the invoice's real approval state, not the commission-payout state.
        ServiceInvoiceLine::find($cancelled->invoice_line_id)->invoice->update(['status' => 'cancelled']);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.lineCount', 2)
                ->where('lines', fn ($lines) => collect($lines)->pluck('invoiceStatus')->sort()->values()->all() === ['cancelled', 'paid'])
            );
    });

    // ── SCOPING ────────────────────────────────────────────────────

    it('scopes an employee to only their own rows', function () {
        $coworker = User::factory()->create(['branch_id' => $this->branch->id]);
        $coworker->addRole(Roles::EMPLOYEE->value);

        ledgerLine($this->employee, $this->branch, ['amount' => 30]);
        ledgerLine($coworker, $this->branch, ['amount' => 70]);

        $this->actingAs($this->employee)
            ->get(route('reports.commissions'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.earned', 30)
                ->has('summary', 1)
                ->where('summary.0.userId', $this->employee->id));
    });

    it('scopes a branch-admin to their own branch', function () {
        ledgerLine($this->employee, $this->branch, ['amount' => 30]);
        ledgerLine($this->otherEmployee, $this->otherBranch, ['amount' => 90]);

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.commissions'))
            ->assertInertia(fn ($page) => $page->where('totals.earned', 30));
    });

    it('lets a super-admin filter by branch', function () {
        ledgerLine($this->employee, $this->branch, ['amount' => 30]);
        ledgerLine($this->otherEmployee, $this->otherBranch, ['amount' => 90]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions', ['branch' => $this->otherBranch->id]))
            ->assertInertia(fn ($page) => $page->where('totals.earned', 90));
    });

    // ── FILTERS ────────────────────────────────────────────────────

    it('filters by status pending', function () {
        ledgerLine($this->employee, $this->branch, ['amount' => 30, 'paid_at' => now()]);
        ledgerLine($this->employee, $this->branch, ['amount' => 45, 'paid_at' => null]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions', ['status' => 'pending']))
            ->assertInertia(fn ($page) => $page->where('totals.earned', 45));
    });

    it('filters by earned_at date range', function () {
        ledgerLine($this->employee, $this->branch, ['amount' => 30, 'earned_at' => now()->subMonths(3)]);
        ledgerLine($this->employee, $this->branch, ['amount' => 45, 'earned_at' => now()->subDay()]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions', [
                'from' => now()->subWeek()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page->where('totals.earned', 45));
    });

    // ── TODAY DEFAULT & PER-DAY BREAKDOWN ──────────────────────────

    it('defaults to today only when no date filter is given', function () {
        ledgerLine($this->employee, $this->branch, ['amount' => 30, 'earned_at' => now()->subDay()]);
        ledgerLine($this->employee, $this->branch, ['amount' => 45]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions'))
            ->assertInertia(fn ($page) => $page->where('totals.earned', 45)
                ->has('byDay', 1)
                ->where('byDay.0.date', now()->toDateString())
                ->where('byDay.0.earned', 45)
                ->where('defaultDate', now()->toDateString()));
    });

    it('lists today with zeroes when no commission was earned at all', function () {
        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions'))
            ->assertInertia(fn ($page) => $page->has('byDay', 1)
                ->where('byDay.0.date', now()->toDateString())
                ->where('byDay.0.earned', 0)
                ->where('byDay.0.lineCount', 0));
    });

    it('keeps a zero row for every quiet day inside a filtered range', function () {
        ledgerLine($this->employee, $this->branch, ['amount' => 30, 'earned_at' => now()->subDays(2)]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions', [
                'from' => now()->subDays(2)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page->has('byDay', 3)
                ->where('byDay.0.earned', 30)
                ->where('byDay.1.earned', 0)
                ->where('byDay.2.date', now()->toDateString())
                ->where('byDay.2.earned', 0));
    });

    // ── AGENT LINE COMMISSIONS («للعمولات») ────────────────────────

    it('sums agent line-commissions per employee without touching their own total', function () {
        ledgerLine($this->employee, $this->branch, ['amount' => 30], ['agent_commission_amount' => 12.5]);
        ledgerLine($this->employee, $this->branch, ['amount' => 20], ['agent_commission_amount' => 7.5]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions'))
            ->assertInertia(fn ($page) => $page
                ->where('summary.0.earned', 50)
                ->where('summary.0.lineCommission', 20)
                ->where('totals.earned', 50)
                ->where('totals.lineCommission', 20));
    });

    it('reports the line commission on the matching drill-down row and daily total', function () {
        ledgerLine($this->employee, $this->branch, ['amount' => 30], ['agent_commission_amount' => 12.5]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions'))
            ->assertInertia(fn ($page) => $page
                ->where('lines.0.lineCommission', 12.5)
                ->where('byDay.0.lineCommission', 12.5));
    });

    it('lists an employee whose invoices only earned agent commissions', function () {
        // An invoice with no ledger row of its own: all of it went to the مندوب.
        $invoice = ServiceInvoice::create([
            'invoice_number' => 'SINV-TST-AGENTONLY',
            'branch_id' => $this->branch->id,
            'user_id' => $this->employee->id,
            'subtotal' => 100,
            'vat_pct' => 15,
            'vat_amount' => 15,
            'total_amount' => 115,
            'employee_commission' => 0,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        ServiceInvoiceLine::create([
            'invoice_id' => $invoice->id,
            'service_name' => 'طباعة',
            'qty' => 1,
            'unit_price' => 100,
            'discount_pct' => 0,
            'subtotal' => 100,
            'commission_pct' => 0,
            'commission_amount' => 0,
            'agent_commission_amount' => 18,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions'))
            ->assertInertia(fn ($page) => $page
                ->has('summary', 1)
                ->where('summary.0.userId', $this->employee->id)
                ->where('summary.0.earned', 0)
                ->where('summary.0.lineCommission', 18)
                ->where('totals.lineCommission', 18));
    });

    it('excludes a due invoice from the line-commission column', function () {
        $invoice = ServiceInvoice::create([
            'invoice_number' => 'SINV-TST-DUE',
            'branch_id' => $this->branch->id,
            'user_id' => $this->employee->id,
            'subtotal' => 100,
            'vat_pct' => 15,
            'vat_amount' => 15,
            'total_amount' => 115,
            'employee_commission' => 0,
            'status' => 'due',
        ]);
        ServiceInvoiceLine::create([
            'invoice_id' => $invoice->id,
            'service_name' => 'طباعة',
            'qty' => 1,
            'unit_price' => 100,
            'discount_pct' => 0,
            'subtotal' => 100,
            'commission_pct' => 0,
            'commission_amount' => 0,
            'agent_commission_amount' => 25,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions'))
            ->assertInertia(fn ($page) => $page->where('totals.lineCommission', 0));
    });

    // ── EXPORT ─────────────────────────────────────────────────────

    it('exports the report as an xlsx download', function () {
        ledgerLine($this->employee, $this->branch, ['amount' => 30]);

        $response = $this->actingAs($this->superAdmin)
            ->get(route('reports.commissions.export'));

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('.xlsx');
    });
});
