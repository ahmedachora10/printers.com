<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function paidProductInvoice(Branch $branch, User $user, array $overrides = []): ProductInvoice
{
    return ProductInvoice::create(array_merge([
        'invoice_number' => 'INV-TST-'.fake()->unique()->numberBetween(1, 999999),
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

function paidServiceInvoice(Branch $branch, User $user, array $overrides = []): ServiceInvoice
{
    return ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-TST-'.fake()->unique()->numberBetween(1, 999999),
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

describe('Sales Report', function () {
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
            ->get(route('reports.sales'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/sales/index')
                ->has('totals')
                ->has('byType')
                ->has('byDay')
                ->has('byEmployee')
                ->has('byPaymentMethod')
                ->where('isSuperAdmin', true));
    });

    it('lets an accountant view the report scoped to their branch', function () {
        $this->actingAs($this->accountant)
            ->get(route('reports.sales'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('isSuperAdmin', false));
    });

    it('forbids an employee from viewing the report', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->get(route('reports.sales'))
            ->assertForbidden();
    });

    it('forbids an agent from viewing the report', function () {
        $agent = User::factory()->create();
        $agent->addRole(Roles::AGENT->value);

        $this->actingAs($agent)
            ->get(route('reports.sales'))
            ->assertForbidden();
    });

    // ── AGGREGATION ────────────────────────────────────────────────

    it('totals realized revenue across product and service invoices', function () {
        paidProductInvoice($this->branch, $this->branchAdmin, ['subtotal' => 100, 'vat_amount' => 15, 'total_amount' => 115]);
        paidServiceInvoice($this->branch, $this->branchAdmin, ['subtotal' => 200, 'vat_amount' => 30, 'total_amount' => 230]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.invoiceCount', 2)
                ->where('totals.subtotal', 300)
                ->where('totals.vat', 45)
                ->where('totals.total', 345));
    });

    it('sums the discount columns into totals.discounts', function () {
        paidProductInvoice($this->branch, $this->branchAdmin, [
            'tier_discount_amount' => 5,
            'coupon_discount' => 3,
            'points_discount' => 2,
            'agent_discount' => 1,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page->where('totals.discounts', 11));
    });

    it('excludes due and cancelled invoices', function () {
        paidProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);
        paidProductInvoice($this->branch, $this->branchAdmin, ['status' => 'due', 'paid_at' => null, 'total_amount' => 500]);
        paidProductInvoice($this->branch, $this->branchAdmin, ['status' => 'cancelled', 'total_amount' => 900]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.invoiceCount', 1)
                ->where('totals.total', 115));
    });

    it('splits revenue by invoice type', function () {
        paidProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);
        paidServiceInvoice($this->branch, $this->branchAdmin, ['total_amount' => 230]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales'))
            ->assertInertia(function ($page) {
                $byType = collect($page->toArray()['props']['byType']);
                expect($byType->firstWhere('type', 'product')['total'])->toEqual(115);
                expect($byType->firstWhere('type', 'service')['total'])->toEqual(230);

                return $page;
            });
    });

    it('groups revenue by payment method', function () {
        $cash = PaymentMethod::factory()->create(['name' => 'نقدًا']);
        paidProductInvoice($this->branch, $this->branchAdmin, ['payment_method_id' => $cash->id, 'total_amount' => 115]);
        paidServiceInvoice($this->branch, $this->branchAdmin, ['payment_method_id' => null, 'total_amount' => 230]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales'))
            ->assertInertia(function ($page) {
                $methods = collect($page->toArray()['props']['byPaymentMethod']);
                expect($methods->firstWhere('methodName', 'نقدًا')['total'])->toEqual(115);
                expect($methods->firstWhere('methodName', 'غير محدد')['total'])->toEqual(230);

                return $page;
            });
    });

    // ── SCOPING ────────────────────────────────────────────────────

    it('scopes a branch-admin to their own branch', function () {
        paidProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);
        paidProductInvoice($this->otherBranch, $this->superAdmin, ['total_amount' => 900]);

        $this->actingAs($this->branchAdmin)
            ->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.total', 115)
                ->where('byBranch', []));
    });

    it('lets a super-admin filter by branch', function () {
        paidProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);
        paidProductInvoice($this->otherBranch, $this->superAdmin, ['total_amount' => 900]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales', ['branch' => $this->otherBranch->id]))
            ->assertInertia(fn ($page) => $page->where('totals.total', 900));
    });

    // ── FILTERS ────────────────────────────────────────────────────

    it('filters to product invoices only', function () {
        paidProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);
        paidServiceInvoice($this->branch, $this->branchAdmin, ['total_amount' => 230]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales', ['type' => 'product']))
            ->assertInertia(fn ($page) => $page
                ->where('totals.total', 115)
                ->where('totals.invoiceCount', 1));
    });

    it('filters by paid_at date range', function () {
        paidProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now()->subMonths(3), 'total_amount' => 300]);
        paidProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now()->subDay(), 'total_amount' => 115]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales', [
                'from' => now()->subWeek()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page->where('totals.total', 115));
    });

    // ── EXPORT ─────────────────────────────────────────────────────

    it('exports the report as an xlsx download', function () {
        paidProductInvoice($this->branch, $this->branchAdmin);

        $response = $this->actingAs($this->superAdmin)->get(route('reports.sales.export'));

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('.xlsx');
    });
});
