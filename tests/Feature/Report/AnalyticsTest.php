<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function anaProductInvoice(Branch $branch, User $user, array $overrides = []): ProductInvoice
{
    return ProductInvoice::create(array_merge([
        'invoice_number' => 'INV-ANA-'.fake()->unique()->numberBetween(1, 999999),
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

function anaServiceInvoice(Branch $branch, User $user, array $overrides = []): ServiceInvoice
{
    return ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-ANA-'.fake()->unique()->numberBetween(1, 999999),
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

function anaServiceLine(ServiceInvoice $invoice, string $name, float $subtotal): ServiceInvoiceLine
{
    return ServiceInvoiceLine::create([
        'invoice_id' => $invoice->id,
        'service_name' => $name,
        'qty' => 1,
        'unit_price' => $subtotal,
        'discount_pct' => 0,
        'subtotal' => $subtotal,
        'commission_pct' => 0,
        'commission_amount' => 0,
    ]);
}

function anaLoyaltyTx(Customer $customer, string $type, int $points): LoyaltyTransaction
{
    return LoyaltyTransaction::create([
        'customer_id' => $customer->id,
        'type' => $type,
        'points' => $points,
        'balance_after' => max(0, $customer->points_balance + $points),
    ]);
}

describe('Advanced Analytics', function () {
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

        $this->otherBranch = Branch::factory()->create();
    });

    // ── ACCESS ─────────────────────────────────────────────────────

    it('lets a super-admin view analytics with every dataset', function () {
        $this->actingAs($this->superAdmin)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('analytics/index')
                ->has('dailyRevenue')
                ->has('salesByType')
                ->has('topServices')
                ->has('employeePerformance')
                ->has('byBranch')
                ->has('loyalty.tierDistribution')
                ->has('loyalty.pointsMonthly')
                ->where('isSuperAdmin', true));
    });

    it('lets an accountant view analytics scoped to their branch', function () {
        $this->actingAs($this->accountant)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isSuperAdmin', false)
                ->where('byBranch', [])
                ->where('branches', []));
    });

    it('forbids an employee from viewing analytics', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->get(route('analytics.index'))
            ->assertForbidden();
    });

    it('forbids an agent from viewing analytics', function () {
        $agent = User::factory()->create();
        $agent->addRole(Roles::AGENT->value);

        $this->actingAs($agent)
            ->get(route('analytics.index'))
            ->assertForbidden();
    });

    // ── DAILY REVENUE ──────────────────────────────────────────────

    it('splits daily revenue into product and service series', function () {
        anaProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);
        anaServiceInvoice($this->branch, $this->branchAdmin, ['total_amount' => 230]);

        $today = now()->toDateString();

        $this->actingAs($this->superAdmin)
            ->get(route('analytics.index', ['from' => $today, 'to' => $today]))
            ->assertInertia(fn ($page) => $page
                ->where('dailyRevenue.0.date', $today)
                ->where('dailyRevenue.0.product', 115)
                ->where('dailyRevenue.0.service', 230)
                ->where('salesByType.product', 115)
                ->where('salesByType.service', 230));
    });

    it('zero-fills days without sales across the range', function () {
        anaProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);

        $this->actingAs($this->superAdmin)
            ->get(route('analytics.index', [
                'from' => now()->subDays(2)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page
                ->count('dailyRevenue', 3)
                ->where('dailyRevenue.0.product', 0)
                ->where('dailyRevenue.2.product', 115));
    });

    it('defaults to today only when no date filter is given', function () {
        anaProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now()->subDay(), 'total_amount' => 500]);
        anaProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);

        $this->actingAs($this->superAdmin)
            ->get(route('analytics.index'))
            ->assertInertia(fn ($page) => $page
                ->count('dailyRevenue', 1)
                ->where('dailyRevenue.0.date', now()->toDateString())
                ->where('salesByType.product', 115)
                ->where('filters.from', now()->toDateString())
                ->where('filters.to', now()->toDateString()));
    });

    it('excludes due and cancelled invoices from revenue', function () {
        anaProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);
        anaProductInvoice($this->branch, $this->branchAdmin, ['status' => 'due', 'paid_at' => null, 'total_amount' => 500]);
        anaProductInvoice($this->branch, $this->branchAdmin, ['status' => 'cancelled', 'total_amount' => 900]);

        $this->actingAs($this->superAdmin)
            ->get(route('analytics.index'))
            ->assertInertia(fn ($page) => $page->where('salesByType.product', 115));
    });

    it('filters revenue by paid_at date range', function () {
        anaProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now()->subDays(10), 'total_amount' => 300]);
        anaProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now(), 'total_amount' => 115]);

        $this->actingAs($this->superAdmin)
            ->get(route('analytics.index', [
                'from' => now()->subDay()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page->where('salesByType.product', 115));
    });

    // ── RANKINGS ───────────────────────────────────────────────────

    it('ranks top services by revenue', function () {
        $invoice = anaServiceInvoice($this->branch, $this->branchAdmin);
        anaServiceLine($invoice, 'تصميم شعار', 300);
        anaServiceLine($invoice, 'طباعة', 100);

        $this->actingAs($this->superAdmin)
            ->get(route('analytics.index'))
            ->assertInertia(fn ($page) => $page
                ->where('topServices.0.name', 'تصميم شعار')
                ->where('topServices.0.total', 300)
                ->where('topServices.1.name', 'طباعة'));
    });

    it('caps top services at ten rows', function () {
        $invoice = anaServiceInvoice($this->branch, $this->branchAdmin);
        foreach (range(1, 12) as $i) {
            anaServiceLine($invoice, "خدمة {$i}", $i * 10);
        }

        $this->actingAs($this->superAdmin)
            ->get(route('analytics.index'))
            ->assertInertia(fn ($page) => $page->count('topServices', 10));
    });

    it('merges employee performance across both invoice tables', function () {
        anaProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);
        anaServiceInvoice($this->branch, $this->branchAdmin, ['total_amount' => 230]);
        anaServiceInvoice($this->branch, $this->accountant, ['total_amount' => 900]);

        $this->actingAs($this->superAdmin)
            ->get(route('analytics.index'))
            ->assertInertia(fn ($page) => $page
                ->where('employeePerformance.0.name', $this->accountant->name)
                ->where('employeePerformance.0.total', 900)
                ->where('employeePerformance.1.name', $this->branchAdmin->name)
                ->where('employeePerformance.1.total', 345)
                ->where('employeePerformance.1.count', 2));
    });

    // ── BRANCH COMPARISON & SCOPING ────────────────────────────────

    it('compares branches for the super-admin', function () {
        anaProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);
        anaProductInvoice($this->otherBranch, $this->superAdmin, ['total_amount' => 900]);

        $this->actingAs($this->superAdmin)
            ->get(route('analytics.index'))
            ->assertInertia(fn ($page) => $page
                ->count('byBranch', 2)
                ->where('byBranch.0.total', 900)
                ->where('byBranch.1.total', 115));
    });

    it('scopes a branch-admin to their own branch', function () {
        anaProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);
        anaProductInvoice($this->otherBranch, $this->superAdmin, ['total_amount' => 900]);

        $this->actingAs($this->branchAdmin)
            ->get(route('analytics.index'))
            ->assertInertia(fn ($page) => $page
                ->where('salesByType.product', 115)
                ->where('byBranch', []));
    });

    it('lets a super-admin filter by branch', function () {
        anaProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);
        anaProductInvoice($this->otherBranch, $this->superAdmin, ['total_amount' => 900]);

        $this->actingAs($this->superAdmin)
            ->get(route('analytics.index', ['branch' => $this->otherBranch->id]))
            ->assertInertia(fn ($page) => $page->where('salesByType.product', 900));
    });

    // ── LOYALTY ────────────────────────────────────────────────────

    it('counts customers per loyalty tier', function () {
        Customer::factory()->create(['branch_id' => $this->branch->id, 'tier' => 'gold']);
        Customer::factory()->create(['branch_id' => $this->branch->id, 'tier' => 'gold']);
        Customer::factory()->create(['branch_id' => $this->branch->id, 'tier' => 'bronze']);
        Customer::factory()->create(['branch_id' => $this->otherBranch->id, 'tier' => 'silver']);

        $this->actingAs($this->branchAdmin)
            ->get(route('analytics.index'))
            ->assertInertia(fn ($page) => $page
                ->where('loyalty.tierDistribution.1.tier', 'bronze')
                ->where('loyalty.tierDistribution.1.count', 1)
                ->where('loyalty.tierDistribution.2.tier', 'silver')
                ->where('loyalty.tierDistribution.2.count', 0)
                ->where('loyalty.tierDistribution.3.tier', 'gold')
                ->where('loyalty.tierDistribution.3.count', 2));
    });

    it('sums monthly points earned vs redeemed with redemptions flipped positive', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'points_balance' => 200]);
        anaLoyaltyTx($customer, 'earn', 100);
        anaLoyaltyTx($customer, 'redeem', -40);

        $month = now()->format('Y-m');

        $this->actingAs($this->branchAdmin)
            ->get(route('analytics.index'))
            ->assertInertia(fn ($page) => $page
                ->where('loyalty.pointsMonthly', fn ($months) => collect($months)
                    ->contains(fn ($m) => $m['month'] === $month && $m['earned'] === 100 && $m['redeemed'] === 40)));
    });

    it('excludes other-branch loyalty activity', function () {
        $foreign = Customer::factory()->create(['branch_id' => $this->otherBranch->id]);
        anaLoyaltyTx($foreign, 'earn', 500);

        $this->actingAs($this->branchAdmin)
            ->get(route('analytics.index'))
            ->assertInertia(fn ($page) => $page
                ->where('loyalty.pointsMonthly', fn ($months) => collect($months)->sum('earned') === 0));
    });
});
