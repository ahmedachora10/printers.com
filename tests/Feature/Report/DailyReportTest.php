<?php

use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\Expense;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Create a (by default DUE) product invoice; supports a created_at override. */
function dailyProductInvoice(Branch $branch, User $user, array $overrides = []): ProductInvoice
{
    $createdAt = $overrides['created_at'] ?? null;
    unset($overrides['created_at']);

    $invoice = ProductInvoice::create(array_merge([
        'invoice_number' => 'INV-DLY-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 15,
        'total_amount' => 115,
        'status' => 'due',
    ], $overrides));

    if ($createdAt) {
        ProductInvoice::query()->whereKey($invoice->id)->update(['created_at' => $createdAt]);
    }

    return $invoice;
}

/** Create a (by default DUE) service invoice; supports a created_at override. */
function dailyServiceInvoice(Branch $branch, User $user, array $overrides = []): ServiceInvoice
{
    $createdAt = $overrides['created_at'] ?? null;
    unset($overrides['created_at']);

    $invoice = ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-DLY-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => 200,
        'vat_pct' => 15,
        'vat_amount' => 30,
        'total_amount' => 230,
        'employee_commission' => 20,
        'status' => 'due',
    ], $overrides));

    if ($createdAt) {
        ServiceInvoice::query()->whereKey($invoice->id)->update(['created_at' => $createdAt]);
    }

    return $invoice;
}

function ledgerRow(Branch $branch, User $user, array $overrides = []): CommissionLedger
{
    return CommissionLedger::create(array_merge([
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'invoice_line_id' => 1,
        'invoice_line_type' => ServiceInvoiceLine::class,
        'amount' => 20,
        'earned_at' => now(),
    ], $overrides));
}

describe('Daily Report', function () {
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

    it('lets a super-admin view the report', function () {
        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/daily/index')
                ->has('rows')
                ->has('totals')
                ->where('showPurchases', true)
                ->where('isSuperAdmin', true));
    });

    it('lets an accountant view the report scoped to their branch', function () {
        $this->actingAs($this->accountant)
            ->get(route('reports.daily'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('isSuperAdmin', false));
    });

    it('forbids an employee from viewing the report', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->get(route('reports.daily'))
            ->assertForbidden();
    });

    it('forbids an agent from viewing the report', function () {
        $agent = User::factory()->create();
        $agent->addRole(Roles::AGENT->value);

        $this->actingAs($agent)
            ->get(route('reports.daily'))
            ->assertForbidden();
    });

    // ── AGGREGATION ────────────────────────────────────────────────

    it('splits net sales into products and services and totals them', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin);
        dailyServiceInvoice($this->branch, $this->branchAdmin);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.products', 100)
                ->where('totals.services', 200)
                ->where('totals.total', 300)
                ->where('totals.vat', 45));
    });

    it('subtracts discounts from the net sales figure', function () {
        // مجموع فرعي 100 وخصومات 11 → إجمالي 89 شامل الضريبة، ضريبته 11.61،
        // فالصافي قبل الضريبة 77.39. الصافي مشتقّ من (الإجمالي − الضريبة).
        dailyProductInvoice($this->branch, $this->branchAdmin, [
            'tier_discount_amount' => 5,
            'coupon_discount' => 3,
            'points_discount' => 2,
            'agent_discount' => 1,
            'vat_amount' => 11.61,
            'total_amount' => 89,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.products', 77.39));
    });

    it('counts due invoices but excludes cancelled ones', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin, ['status' => 'due', 'subtotal' => 100]);
        dailyProductInvoice($this->branch, $this->branchAdmin, [
            'status' => 'paid', 'paid_at' => now(), 'subtotal' => 50, 'vat_amount' => 7.5, 'total_amount' => 57.5,
        ]);
        dailyProductInvoice($this->branch, $this->branchAdmin, [
            'status' => 'cancelled', 'subtotal' => 900, 'vat_amount' => 135, 'total_amount' => 1035,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.products', 150));
    });

    it('reports realized commission from the ledger', function () {
        ledgerRow($this->branch, $this->branchAdmin, ['amount' => 20]);
        ledgerRow($this->branch, $this->accountant, ['amount' => 5]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.commission', 25));
    });

    it('sums purchases from expenses and received stock', function () {
        Expense::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->branchAdmin->id, 'total' => 50, 'date' => today()]);
        StockMovement::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->branchAdmin->id,
            'type' => StockMovementTypeEnum::PURCHASE_IN,
            'qty' => 10,
            'unit_cost' => 5,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.purchases', 100));
    });

    it('computes remaining as total minus commission minus purchases', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin); // net 100
        dailyServiceInvoice($this->branch, $this->branchAdmin); // net 200
        ledgerRow($this->branch, $this->branchAdmin, ['amount' => 20]);
        Expense::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->branchAdmin->id, 'total' => 80, 'date' => today()]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.remaining', 200));
    });

    // ── EMPLOYEE FILTER ────────────────────────────────────────────

    it('scopes sales and commission to a chosen employee and hides purchases', function () {
        dailyServiceInvoice($this->branch, $this->branchAdmin); // net 200 for branchAdmin
        dailyServiceInvoice($this->branch, $this->accountant, ['subtotal' => 300, 'vat_amount' => 45, 'total_amount' => 345]);
        ledgerRow($this->branch, $this->branchAdmin, ['amount' => 20]);
        ledgerRow($this->branch, $this->accountant, ['amount' => 99]);
        Expense::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->branchAdmin->id, 'total' => 500, 'date' => today()]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', ['employee' => $this->branchAdmin->id]))
            ->assertInertia(fn ($page) => $page
                ->where('showPurchases', false)
                ->where('detailed', false)
                ->where('totals.services', 200)
                ->where('totals.commission', 20)
                ->where('totals.purchases', 0));
    });

    it('keeps one row per day for a single selected employee', function () {
        dailyServiceInvoice($this->branch, $this->branchAdmin);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', ['employee' => $this->branchAdmin->id]))
            ->assertInertia(fn ($page) => $page
                ->where('detailed', false)
                ->has('rows', 1)
                ->where('rows.0.employeeName', null)
                ->where('rows.0.isTotal', false));
    });

    // ── MULTI-EMPLOYEE FILTER ──────────────────────────────────────

    it('accepts a comma-separated employee list and sums only those employees', function () {
        $other = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'زياد']);
        $other->addRole(Roles::EMPLOYEE->value);

        dailyServiceInvoice($this->branch, $this->branchAdmin); // net 200
        dailyServiceInvoice($this->branch, $this->accountant, ['subtotal' => 300, 'vat_amount' => 45, 'total_amount' => 345]);
        dailyServiceInvoice($this->branch, $other, ['subtotal' => 900, 'vat_amount' => 135, 'total_amount' => 1035]);
        ledgerRow($this->branch, $this->branchAdmin, ['amount' => 20]);
        ledgerRow($this->branch, $this->accountant, ['amount' => 30]);
        ledgerRow($this->branch, $other, ['amount' => 99]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', ['employee' => $this->branchAdmin->id.','.$this->accountant->id]))
            ->assertInertia(fn ($page) => $page
                ->where('detailed', true)
                ->where('showPurchases', false)
                ->where('totals.services', 500)
                ->where('totals.commission', 50));
    });

    it('splits each day into one row per employee plus a day total row', function () {
        $this->branchAdmin->update(['name' => 'أحمد']);
        $this->accountant->update(['name' => 'بدر']);

        dailyServiceInvoice($this->branch, $this->branchAdmin); // net 200
        dailyServiceInvoice($this->branch, $this->accountant, ['subtotal' => 300, 'vat_amount' => 45, 'total_amount' => 345]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', ['employee' => [$this->branchAdmin->id, $this->accountant->id]]))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 3)
                ->where('rows.0.employeeId', $this->branchAdmin->id)
                ->where('rows.0.services', 200)
                ->where('rows.1.employeeId', $this->accountant->id)
                ->where('rows.1.services', 300)
                ->where('rows.2.isTotal', true)
                ->where('rows.2.employeeId', null)
                ->where('rows.2.services', 500));
    });

    it('does not double-count the day total rows in the grand totals', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin); // net 100
        dailyProductInvoice($this->branch, $this->accountant, ['subtotal' => 50, 'vat_amount' => 7.5, 'total_amount' => 57.5]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', ['employee' => $this->branchAdmin->id.','.$this->accountant->id]))
            ->assertInertia(fn ($page) => $page
                ->where('totals.products', 150)
                ->where('totals.dayCount', 1));
    });

    it('rejects an unknown employee id', function () {
        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', ['employee' => '999999']))
            ->assertSessionHasErrors('employee.0');
    });

    it('ignores a legacy employee=all query value', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', ['employee' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('showPurchases', true)
                ->where('filters.employee', null)
                ->where('totals.products', 100));
    });

    it('exports the detailed multi-employee report', function () {
        dailyServiceInvoice($this->branch, $this->branchAdmin);
        dailyServiceInvoice($this->branch, $this->accountant);

        $response = $this->actingAs($this->superAdmin)
            ->get(route('reports.daily.export', ['employee' => $this->branchAdmin->id.','.$this->accountant->id]));

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('.xlsx');
    });

    // ── SCOPING ────────────────────────────────────────────────────

    it('scopes a branch-admin to their own branch', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin); // net 100
        dailyProductInvoice($this->otherBranch, $this->superAdmin, ['subtotal' => 900, 'total_amount' => 1035, 'vat_amount' => 135]);

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.products', 100)
                ->where('branches', []));
    });

    it('lets a super-admin filter by branch', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin);
        dailyProductInvoice($this->otherBranch, $this->superAdmin, ['subtotal' => 900, 'total_amount' => 1035, 'vat_amount' => 135]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', ['branch' => $this->otherBranch->id]))
            ->assertInertia(fn ($page) => $page->where('totals.products', 900));
    });

    // ── FILTERS ────────────────────────────────────────────────────

    it('filters by created_at date range', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin, ['created_at' => now()->subMonths(3), 'subtotal' => 300]);
        dailyProductInvoice($this->branch, $this->branchAdmin, ['created_at' => now()->subDay(), 'subtotal' => 100]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', [
                'from' => now()->subWeek()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page->where('totals.products', 100));
    });

    // ── TODAY DEFAULT & ZERO-FILLED DAYS ───────────────────────────

    it('defaults to today only when no date filter is given', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin, ['created_at' => now()->subDay(), 'subtotal' => 500]);
        dailyProductInvoice($this->branch, $this->branchAdmin, ['subtotal' => 100]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.products', 100)
                ->has('rows', 1)
                ->where('rows.0.date', now()->toDateString())
                ->where('defaultDate', now()->toDateString()));
    });

    it('lists today with zeroes when nothing happened at all', function () {
        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->has('rows', 1)
                ->where('rows.0.date', now()->toDateString())
                ->where('rows.0.total', 0)
                ->where('rows.0.products', 0)
                ->where('rows.0.services', 0));
    });

    it('keeps a zero row for every quiet day inside a filtered range', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin, ['created_at' => now()->subDays(2), 'subtotal' => 100]);

        // 3-day window: only the oldest day sold anything, the other two are quiet.
        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', [
                'from' => now()->subDays(2)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page->has('rows', 3)
                ->where('rows.0.date', now()->subDays(2)->toDateString())
                ->where('rows.0.products', 100)
                ->where('rows.1.total', 0)
                ->where('rows.2.total', 0)
                ->where('rows.2.date', now()->toDateString()));
    });

    // ── EXPORT ─────────────────────────────────────────────────────

    it('exports the report as an xlsx download', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin);

        $response = $this->actingAs($this->superAdmin)->get(route('reports.daily.export'));

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('.xlsx');
    });
});
