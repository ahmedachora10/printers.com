<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\Product;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dashProductInvoice(Branch $branch, User $user, array $overrides = []): ProductInvoice
{
    return ProductInvoice::create(array_merge([
        'invoice_number' => 'INV-DSH-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 15,
        'total_amount' => 115,
        'status' => 'paid',
        'paid_at' => now(),
    ], $overrides));
}

function dashServiceInvoice(Branch $branch, User $user, array $overrides = []): ServiceInvoice
{
    return ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-DSH-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => 200,
        'vat_pct' => 15,
        'vat_amount' => 30,
        'total_amount' => 230,
        'employee_commission' => 20,
        'status' => 'paid',
        'paid_at' => now(),
    ], $overrides));
}

describe('Dashboard', function () {
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
    });

    // ── ACCESS ─────────────────────────────────────────────────────

    it('renders the dashboard for a super-admin', function () {
        $this->actingAs($this->superAdmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('kpis')
                ->has('recentInvoices')
                ->has('topServices')
                ->where('scope.isSuper', true));
    });

    it('redirects an agent to their portal', function () {
        $agent = User::factory()->create();
        $agent->addRole(Roles::AGENT->value);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertRedirect(route('agent-portal.index'));
    });

    it('hides the low-stock tile for employees', function () {
        $this->actingAs($this->employee)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('scope.isEmployee', true)
                ->where('kpis.lowStockCount', null));
    });

    // ── KPIs ───────────────────────────────────────────────────────

    it('totals today and month sales from paid invoices', function () {
        dashProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now(), 'total_amount' => 115]);
        dashServiceInvoice($this->branch, $this->branchAdmin, ['paid_at' => now()->startOfMonth()->addDay(), 'total_amount' => 230]);

        $this->actingAs($this->branchAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('kpis.todaySales', 115)
                ->where('kpis.monthSales', 345));
    });

    it('excludes non-today invoices from today sales', function () {
        dashProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now()->subDays(10), 'total_amount' => 500]);

        $this->actingAs($this->branchAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('kpis.todaySales', 0));
    });

    it('sums outstanding due invoices', function () {
        dashProductInvoice($this->branch, $this->branchAdmin, ['status' => 'due', 'paid_at' => null, 'total_amount' => 400]);
        dashProductInvoice($this->branch, $this->branchAdmin, ['status' => 'paid', 'total_amount' => 115]);

        $this->actingAs($this->branchAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('kpis.outstandingDue', 400));
    });

    it('sums unpaid commissions from the ledger', function () {
        CommissionLedger::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->employee->id, 'amount' => 60, 'paid_at' => null]);
        CommissionLedger::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->employee->id, 'amount' => 40, 'paid_at' => now()]);

        $this->actingAs($this->branchAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('kpis.pendingCommissions', 60));
    });

    it('counts products at or below minimum stock', function () {
        Product::factory()->create(['branch_id' => $this->branch->id, 'current_stock' => 0, 'min_stock_level' => 5, 'is_active' => true]);
        Product::factory()->create(['branch_id' => $this->branch->id, 'current_stock' => 50, 'min_stock_level' => 5, 'is_active' => true]);

        $this->actingAs($this->branchAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('kpis.lowStockCount', 1));
    });

    // ── SCOPING ────────────────────────────────────────────────────

    it('scopes a branch-admin to their own branch', function () {
        dashProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);
        dashProductInvoice($this->otherBranch, $this->superAdmin, ['total_amount' => 900]);

        $this->actingAs($this->branchAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('kpis.todaySales', 115));
    });

    it('scopes an employee to their own sales', function () {
        $coworker = User::factory()->create(['branch_id' => $this->branch->id]);
        $coworker->addRole(Roles::EMPLOYEE->value);

        dashServiceInvoice($this->branch, $this->employee, ['total_amount' => 230]);
        dashServiceInvoice($this->branch, $coworker, ['total_amount' => 900]);

        $this->actingAs($this->employee)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('kpis.todaySales', 230));
    });

    // ── LISTS ──────────────────────────────────────────────────────

    it('returns the most recent invoices with type and status', function () {
        dashProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);

        $this->actingAs($this->branchAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('recentInvoices.0.type', 'product')
                ->where('recentInvoices.0.status', 'paid')
                ->has('recentInvoices.0.invoiceNumber'));
    });

    it('ranks top services this month by revenue', function () {
        $invoice = dashServiceInvoice($this->branch, $this->branchAdmin);
        ServiceInvoiceLine::create([
            'invoice_id' => $invoice->id,
            'service_name' => 'تصميم شعار',
            'qty' => 1,
            'unit_price' => 300,
            'discount_pct' => 0,
            'subtotal' => 300,
            'commission_pct' => 0,
            'commission_amount' => 0,
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
        ]);

        $this->actingAs($this->branchAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('topServices.0.name', 'تصميم شعار')
                ->where('topServices.0.total', 300)
                ->where('topServices.1.name', 'طباعة'));
    });
});
