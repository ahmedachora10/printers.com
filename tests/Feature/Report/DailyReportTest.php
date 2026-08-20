<?php

use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\Expense;
use App\Models\InvoicePayment;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create a product invoice — APPROVED (paid) today by default, since only
 * approved invoices reach the report. `created_at` may still be overridden to
 * prove the report ignores it; pass `status => 'due'` for an unapproved one.
 */
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
        'status' => 'paid',
        'paid_at' => now(),
    ], $overrides));

    if ($createdAt) {
        ProductInvoice::query()->whereKey($invoice->id)->update(['created_at' => $createdAt]);
    }

    return $invoice;
}

/** Create a service invoice — APPROVED (paid) today by default; see the product helper. */
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
        'status' => 'paid',
        'paid_at' => now(),
    ], $overrides));

    if ($createdAt) {
        ServiceInvoice::query()->whereKey($invoice->id)->update(['created_at' => $createdAt]);
    }

    return $invoice;
}

/** Record an instalment against an invoice, dated $paidAt. */
function dailyPayment(Branch $branch, User $user, $invoice, float $amount, $paidAt): void
{
    InvoicePayment::create([
        'invoice_id' => $invoice->id,
        'invoice_type' => $invoice::class,
        'branch_id' => $branch->id,
        'amount' => $amount,
        'paid_at' => $paidAt,
        'recorded_by' => $user->id,
    ]);
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

    it('splits sales into products and services and totals them including VAT', function () {
        // تاسك 58: الأرقام شاملة الضريبة — 115 و230 لا 100 و200، والضريبة تبقى
        // في عمودها للعرض.
        dailyProductInvoice($this->branch, $this->branchAdmin);
        dailyServiceInvoice($this->branch, $this->branchAdmin);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.products', 115)
                ->where('totals.services', 230)
                ->where('totals.total', 345)
                ->where('totals.vat', 45));
    });

    it('reports the discounted total, not the pre-discount subtotal', function () {
        // مجموع فرعي 100 وخصومات 11 → إجمالي 89 شامل الضريبة. الرقم المعروض هو
        // الإجمالي المخزَّن، فالخصومات مطروحة منه أصلاً (تاسك 58).
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
            ->assertInertia(fn ($page) => $page->where('totals.products', 89));
    });

    // ── المرتجع والإلغاء في التقرير اليومي (تاسك 43) ───────────────

    it('drops a returned invoice out of sales and out of collected', function () {
        // فاتورة معتمدة ثم مرتجعة: كانت تبقى ضمن المبيعات والمحصَّل والضريبة.
        dailyProductInvoice($this->branch, $this->branchAdmin, [
            'status' => 'returned', 'paid_at' => now(), 'vat_amount' => 15, 'total_amount' => 115,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.products', 0)
                ->where('totals.collected', 0)
                ->where('totals.vat', 0));
    });

    it('does not subtract a fully returned invoice twice', function () {
        // تاسك 46: الاسترجاع يضع الحالة `returned` **ويُنشئ صفّ مرتجع**. الفاتورة
        // ساقطة أصلاً من المبيعات والمحصَّل، فطرح صفّ مرتجعها فوق ذلك كان يُخرج
        // المحصَّل بالسالب. يبقى المبلغ ظاهراً في عمود المرتجعات للاطّلاع فقط.
        $invoice = dailyProductInvoice($this->branch, $this->branchAdmin, [
            'status' => 'returned', 'paid_at' => now(), 'vat_amount' => 15, 'total_amount' => 115,
        ]);

        Refund::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->branchAdmin->id,
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'invoice_type' => ProductInvoice::class,
            'amount' => 115,
            'reason' => 'استرجاع الفاتورة',
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.collected', 0)
                ->where('totals.refunds', 115)
                ->where('totals.products', 0)
                ->where('totals.remaining', 0));
    });

    it('subtracts a partial refund from the collected amount and shows it', function () {
        $invoice = dailyProductInvoice($this->branch, $this->branchAdmin, [
            'status' => 'paid', 'paid_at' => now(), 'vat_amount' => 15, 'total_amount' => 115,
        ]);

        Refund::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->branchAdmin->id,
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'invoice_type' => ProductInvoice::class,
            'amount' => 40,
            'reason' => 'مرتجع جزئي',
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page
                // حُصِّل 115 ورُدَّ 40، فالصافي 75 — والمرتجع ظاهر في عموده.
                ->where('totals.collected', 75)
                ->where('totals.refunds', 40)
                // المبيعات المستحقة لا تتأثر: الفاتورة قائمة ولم تُرتجع كلها.
                ->where('totals.products', 115));
    });

    it('agrees with the commission report on the same day', function () {
        // تاسك 10: عمولة فاتورة مرتجعة غير مدفوعة تسقط من التقريرين معاً، فلا
        // يعطيان رقمين مختلفين لليوم نفسه.
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $returned = dailyServiceInvoice($this->branch, $employee, ['status' => 'returned']);
        $line = ServiceInvoiceLine::create([
            'invoice_id' => $returned->id,
            'service_name' => 'طباعة',
            'qty' => 1,
            'unit_price' => 200,
            'discount_pct' => 0,
            'subtotal' => 200,
            'commission_pct' => 10,
            'commission_amount' => 20,
        ]);
        ledgerRow($this->branch, $employee, ['invoice_line_id' => $line->id, 'amount' => 20]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.commission', 0));
    });

    // ── لا مبيعات قبل اعتماد المحاسب ───────────────────────────────

    it('counts only approved invoices, excluding due and cancelled ones', function () {
        // الآجلة أنشأها الموظف ولم يعتمدها أحد بعد، والملغاة لم تصر بيعاً قط.
        dailyProductInvoice($this->branch, $this->branchAdmin, ['status' => 'due', 'paid_at' => null, 'subtotal' => 100]);
        dailyProductInvoice($this->branch, $this->branchAdmin, [
            'status' => 'cancelled', 'paid_at' => null, 'subtotal' => 900, 'vat_amount' => 135, 'total_amount' => 1035,
        ]);
        dailyProductInvoice($this->branch, $this->branchAdmin, [
            'subtotal' => 50, 'vat_amount' => 7.5, 'total_amount' => 57.5,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.products', 57.5)->where('totals.vat', 7.5));
    });

    it('keeps an employee\'s due invoice out of the report until it is approved', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $invoice = dailyServiceInvoice($this->branch, $employee, ['status' => 'due', 'paid_at' => null]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.services', 0)->where('totals.vat', 0));

        // يعتمدها المحاسب الآن، فتدخل التقرير.
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.services', 230)->where('totals.vat', 30));
    });

    it('books an invoice on its approval day, not on the day the employee created it', function () {
        // أُنشئت قبل يومين واعتُمدت اليوم: تقرير يوم الإنشاء يبقى صفراً فلا يتغيّر
        // تقرير يومٍ مضى بعد طباعته.
        dailyProductInvoice($this->branch, $this->branchAdmin, [
            'created_at' => now()->subDays(2),
            'paid_at' => now(),
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', [
                'from' => now()->subDays(2)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.date', now()->subDays(2)->toDateString())
                ->where('rows.0.products', 0)
                ->where('rows.2.date', now()->toDateString())
                ->where('rows.2.products', 115));
    });

    it('counts a partially paid invoice in full on the day of its first instalment', function () {
        // العربون اعتمادٌ بذاته: تدخل الفاتورة بكامل قيمتها في «الإجمالي»
        // بينما «المحصَّل» لا يحمل إلا ما قُبض فعلاً.
        $invoice = dailyProductInvoice($this->branch, $this->branchAdmin, [
            'status' => 'partially_paid',
            'paid_at' => null,
            'created_at' => now()->subDay(),
        ]);
        dailyPayment($this->branch, $this->accountant, $invoice, 40, now());

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.products', 115)
                ->where('totals.vat', 15)
                ->where('totals.collected', 40));
    });

    it('keeps an instalment invoice on its deposit day after the final payment settles it', function () {
        // paid_at يصير لحظة آخر دفعة، فلولا اعتماد **أول** دفعة لقفز الصف إلى يوم آخر.
        $invoice = dailyProductInvoice($this->branch, $this->branchAdmin, [
            'paid_at' => now(),
            'created_at' => now()->subDays(3),
        ]);
        dailyPayment($this->branch, $this->accountant, $invoice, 40, now()->subDays(2));
        dailyPayment($this->branch, $this->accountant, $invoice, 75, now());

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', [
                'from' => now()->subDays(2)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page
                // يوم العربون يحمل كامل قيمة الفاتورة…
                ->where('rows.0.date', now()->subDays(2)->toDateString())
                ->where('rows.0.products', 115)
                ->where('rows.0.collected', 40)
                // …ويوم السداد الأخير يحمل تحصيله وحده.
                ->where('rows.2.products', 0)
                ->where('rows.2.collected', 75));
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

    it('computes remaining as gross sales minus refunds minus purchases', function () {
        // تاسك 58: العمولة لم تعد تُطرح. 115 + 230 − 80 مشتريات = 265.
        dailyProductInvoice($this->branch, $this->branchAdmin); // 115 شاملة الضريبة
        dailyServiceInvoice($this->branch, $this->branchAdmin); // 230 شاملة الضريبة
        ledgerRow($this->branch, $this->branchAdmin, ['amount' => 20]);
        Expense::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->branchAdmin->id, 'total' => 80, 'date' => today()]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.remaining', 265)
                // العمولة عمود عرض لا يدخل المعادلة.
                ->where('totals.commission', 20));
    });

    it('matches the client worked example: 115 - 10 - 10 = 95', function () {
        // مثال ملاحظات 02/03/1448 صفحة 7: خدمات 115 (100 + 15 ضريبة)،
        // مرتجعات 10، مشتريات 10، عمولة 45 → الإجمالي 115 والمتبقي 95.
        $invoice = dailyServiceInvoice($this->branch, $this->branchAdmin, [
            'subtotal' => 100, 'vat_amount' => 15, 'total_amount' => 115,
        ]);

        Refund::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->branchAdmin->id,
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'invoice_type' => ServiceInvoice::class,
            'amount' => 10,
            'reason' => 'مرتجع جزئي',
        ]);

        ledgerRow($this->branch, $this->branchAdmin, ['amount' => 45]);
        Expense::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->branchAdmin->id, 'total' => 10, 'date' => today()]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.services', 115)
                ->where('totals.total', 115)
                ->where('totals.refunds', 10)
                ->where('totals.purchases', 10)
                ->where('totals.commission', 45)
                ->where('totals.vat', 15)
                ->where('totals.remaining', 95));
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
                ->where('totals.services', 230)
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
                ->where('totals.services', 575)
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
                ->where('rows.0.services', 230)
                ->where('rows.1.employeeId', $this->accountant->id)
                ->where('rows.1.services', 345)
                ->where('rows.2.isTotal', true)
                ->where('rows.2.employeeId', null)
                ->where('rows.2.services', 575));
    });

    it('does not double-count the day total rows in the grand totals', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin); // net 100
        dailyProductInvoice($this->branch, $this->accountant, ['subtotal' => 50, 'vat_amount' => 7.5, 'total_amount' => 57.5]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', ['employee' => $this->branchAdmin->id.','.$this->accountant->id]))
            ->assertInertia(fn ($page) => $page
                ->where('totals.products', 172.5)
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
                ->where('totals.products', 115));
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
                ->where('totals.products', 115)
                ->where('branches', []));
    });

    it('lets a super-admin filter by branch', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin);
        dailyProductInvoice($this->otherBranch, $this->superAdmin, ['subtotal' => 900, 'total_amount' => 1035, 'vat_amount' => 135]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', ['branch' => $this->otherBranch->id]))
            ->assertInertia(fn ($page) => $page->where('totals.products', 1035));
    });

    // ── FILTERS ────────────────────────────────────────────────────

    it('filters by the approval date range', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now()->subMonths(3), 'subtotal' => 300]);
        dailyProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now()->subDay(), 'subtotal' => 100]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', [
                'from' => now()->subWeek()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page->where('totals.products', 115));
    });

    // ── TODAY DEFAULT & ZERO-FILLED DAYS ───────────────────────────

    it('defaults to today only when no date filter is given', function () {
        dailyProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now()->subDay(), 'subtotal' => 500]);
        dailyProductInvoice($this->branch, $this->branchAdmin, ['subtotal' => 100]);

        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily'))
            ->assertInertia(fn ($page) => $page->where('totals.products', 115)
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
        dailyProductInvoice($this->branch, $this->branchAdmin, ['paid_at' => now()->subDays(2), 'subtotal' => 100]);

        // 3-day window: only the oldest day sold anything, the other two are quiet.
        $this->actingAs($this->superAdmin)
            ->get(route('reports.daily', [
                'from' => now()->subDays(2)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page->has('rows', 3)
                ->where('rows.0.date', now()->subDays(2)->toDateString())
                ->where('rows.0.products', 115)
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
