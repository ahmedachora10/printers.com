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

    // ── TODAY DEFAULT & ZERO-FILLED DAYS ───────────────────────────

    it('defaults to today only when no date filter is given', function () {
        paidProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now()->subDay(), 'total_amount' => 500]);
        paidProductInvoice($this->branch, $this->branchAdmin, ['total_amount' => 115]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page->where('totals.total', 115)
                ->has('byDay', 1)
                ->where('byDay.0.date', now()->toDateString())
                ->where('defaultDate', now()->toDateString()));
    });

    it('keeps a zero row for every quiet day inside a filtered range', function () {
        paidProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now()->subDays(2), 'total_amount' => 115]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales', [
                'from' => now()->subDays(2)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page->has('byDay', 3)
                ->where('byDay.0.total', 115)
                ->where('byDay.1.total', 0)
                ->where('byDay.1.count', 0)
                ->where('byDay.2.date', now()->toDateString())
                ->where('byDay.2.total', 0));
    });

    // ── المرتجعات ──────────────────────────────────────────────────
    //
    // المرتجع حدثُ تحصيلٍ سالب: يُطرح من الإيراد ويُعرض مجموعُه مستقلاً. أما
    // الفاتورة المرتجعة بالكامل فحالتها `returned` تُخرجها من التقرير أصلاً،
    // فلا يُطرح مرتجعها فوق ذلك.

    it('subtracts a partial refund from realized revenue', function () {
        $invoice = paidProductInvoice($this->branch, $this->branchAdmin); // total 115

        $this->actingAs($this->branchAdmin)->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => 15,
            'reason' => 'مرتجع جزئي',
        ])->assertRedirect();

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.total', 100)
                ->where('totals.refunds', 15)
                ->where('totals.invoiceCount', 1));
    });

    it('prorates a partial refund across subtotal and VAT', function () {
        $invoice = paidProductInvoice($this->branch, $this->branchAdmin); // 100 + 15 ضريبة = 115

        $this->actingAs($this->branchAdmin)->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => 57.5, // نصف الفاتورة
            'reason' => 'نصف المبلغ',
        ])->assertRedirect();

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.subtotal', 50)
                ->where('totals.vat', 7.5)
                ->where('totals.total', 57.5));
    });

    it('does not subtract twice when the refund empties the invoice', function () {
        // المرتجع الكامل يجعل الحالة `returned`، فتسقط الفاتورة من التقرير كلياً؛
        // طرحُ صفّ مرتجعها فوق ذلك كان سيُخرج الإيراد بالسالب.
        $invoice = paidProductInvoice($this->branch, $this->branchAdmin); // total 115

        $this->actingAs($this->branchAdmin)->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => 115,
            'reason' => 'مرتجع كامل',
        ])->assertRedirect();

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.total', 0)
                ->where('totals.refunds', 0)
                ->where('totals.invoiceCount', 0));
    });

    it('dates the refund by the day the money went back, not the sale', function () {
        $invoice = paidProductInvoice($this->branch, $this->branchAdmin, [
            'paid_at' => now()->subDays(5),
        ]);

        $this->actingAs($this->branchAdmin)->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => 15,
            'reason' => 'مرتجع اليوم على بيع قديم',
        ])->assertRedirect();

        // نافذةٌ تضمّ المرتجع وحده: إيرادٌ سالب وعددُ فواتير صفر — الفاتورة لم
        // تُبَع في هذه الفترة.
        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales', ['from' => now()->toDateString(), 'to' => now()->toDateString()]))
            ->assertInertia(fn ($page) => $page
                ->where('totals.total', -15)
                ->where('totals.refunds', 15)
                ->where('totals.invoiceCount', 0));

        // ونافذةٌ تضمّ البيع وحده: الإيراد كاملاً بلا خصم.
        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales', [
                'from' => now()->subDays(5)->toDateString(),
                'to' => now()->subDays(5)->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page->where('totals.total', 115));
    });

    it('attributes the refund to the employee who raised the invoice', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $invoice = paidServiceInvoice($this->branch, $employee); // total 230

        $this->actingAs($this->branchAdmin)->post(route('refunds.store'), [
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'amount' => 30,
            'reason' => 'مرتجع جزئي',
        ])->assertRedirect();

        $this->actingAs($this->superAdmin)
            ->get(route('reports.sales'))
            ->assertInertia(fn ($page) => $page
                ->has('byEmployee', 1)
                ->where('byEmployee.0.userId', $employee->id)
                ->where('byEmployee.0.total', 200));
    });

    // ── EXPORT ─────────────────────────────────────────────────────

    it('exports the report as an xlsx download', function () {
        paidProductInvoice($this->branch, $this->branchAdmin);

        $response = $this->actingAs($this->superAdmin)->get(route('reports.sales.export'));

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('.xlsx');
    });
});
