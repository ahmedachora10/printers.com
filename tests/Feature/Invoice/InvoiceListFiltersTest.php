<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\PaymentMethod;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * تاسك 92 — الفلاتر الإضافية على /invoices: الموظف، ونوع الخدمة، وطريقة
 * الدفع، والخيار الجامع «غير مسددة (عليها متبقٍ)». النافذة الواحدة والتراكب
 * قائمان منذ تاسك 17، فالاختبار على الحقول الجديدة وتراكبها.
 */
function filterInvoice(int $branchId, int $userId, array $overrides = [], ?int $branchServiceId = null): ServiceInvoice
{
    $invoice = ServiceInvoice::create(array_merge([
        'invoice_number' => 'SINV-FLT-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branchId,
        'user_id' => $userId,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 13.04,
        'total_amount' => 100,
        'employee_commission' => 0,
        'status' => InvoiceStatusEnum::DUE,
    ], $overrides));

    ServiceInvoiceLine::create([
        'invoice_id' => $invoice->id,
        'branch_service_id' => $branchServiceId,
        'service_name' => 'خدمة',
        'qty' => 1,
        'unit_price' => 100,
        'discount_pct' => 0,
        'subtotal' => 100,
        'commission_pct' => 0,
        'commission_amount' => 0,
    ]);

    return $invoice;
}

/**
 * خدمةٌ مربوطة بفرع. الربط يمرّ بالعلاقة لا بـ`BranchService::create()`:
 * الموديل Pivot، فالإنشاء المباشر لا يُرجع مفتاحاً يُصفّى به.
 */
function filterService(Branch $branch, string $name): BranchService
{
    $template = ServiceTemplate::factory()->create(['name' => $name]);
    $template->branches()->attach($branch->id, ['base_commission_pct' => 10, 'is_active' => true]);

    return BranchService::query()
        ->where('branch_id', $branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

/** أرقام الفواتير في حمولة القائمة. */
function listedNumbers(array $items): array
{
    return collect($items)->pluck('invoiceNumber')->sort()->values()->all();
}

describe('Invoice list filters', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->alice = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'أليس']);
        $this->alice->addRole(Roles::EMPLOYEE->value);
        $this->bob = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'بدر']);
        $this->bob->addRole(Roles::EMPLOYEE->value);

        $this->cash = PaymentMethod::factory()->create(['name' => 'نقد']);
        $this->card = PaymentMethod::factory()->create(['name' => 'شبكة']);

        $this->printing = filterService($this->branch, 'طباعة');
        $this->design = filterService($this->branch, 'تصميم');
    });

    // ── الخيار الجامع ────────────────────────────────────────────

    it('gathers the due and the partially paid under one unsettled option', function () {
        $due = filterInvoice($this->branch->id, $this->alice->id);
        $partial = filterInvoice($this->branch->id, $this->alice->id, ['status' => InvoiceStatusEnum::PARTIALLY_PAID]);
        filterInvoice($this->branch->id, $this->alice->id, ['status' => InvoiceStatusEnum::PAID]);

        $this->actingAs($this->branchAdmin)
            ->get(route('invoices.index', ['status' => 'unsettled']))
            ->assertInertia(fn ($page) => $page->has('items.data', 2)
                ->where('items.data', fn ($rows) => listedNumbers($rows->toArray())
                    === collect([$due->invoice_number, $partial->invoice_number])->sort()->values()->all()));
    });

    it('serves the status options from the enum, so «غير مسددة» is what the server calls it', function () {
        $this->actingAs($this->branchAdmin)
            ->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page
                ->where('statusOptions.0.value', 'unsettled')
                ->where('statusOptions.3.value', 'due')
                ->where('statusOptions.3.label', 'غير مسددة'));
    });

    // ── الحقول الأربعة ───────────────────────────────────────────

    it('filters by the employee who raised the invoice', function () {
        $hers = filterInvoice($this->branch->id, $this->alice->id);
        filterInvoice($this->branch->id, $this->bob->id);

        $this->actingAs($this->branchAdmin)
            ->get(route('invoices.index', ['user_id' => $this->alice->id]))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)
                ->where('items.data.0.invoiceNumber', $hers->invoice_number));
    });

    it('filters by payment method', function () {
        $paidCash = filterInvoice($this->branch->id, $this->alice->id, ['payment_method_id' => $this->cash->id]);
        filterInvoice($this->branch->id, $this->alice->id, ['payment_method_id' => $this->card->id]);

        $this->actingAs($this->branchAdmin)
            ->get(route('invoices.index', ['payment_method_id' => $this->cash->id]))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)
                ->where('items.data.0.invoiceNumber', $paidCash->invoice_number));
    });

    it('filters by the service on the line, not by its printed name', function () {
        $printed = filterInvoice($this->branch->id, $this->alice->id, [], $this->printing->id);
        filterInvoice($this->branch->id, $this->alice->id, [], $this->design->id);

        $this->actingAs($this->branchAdmin)
            ->get(route('invoices.index', ['branch_service_id' => $this->printing->id]))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)
                ->where('items.data.0.invoiceNumber', $printed->invoice_number));
    });

    it('drops product invoices out of the union when a service is chosen', function () {
        ProductInvoice::create([
            'invoice_number' => 'INV-FLT-001',
            'branch_id' => $this->branch->id,
            'user_id' => $this->alice->id,
            'subtotal' => 50,
            'vat_pct' => 15,
            'vat_amount' => 6.52,
            'total_amount' => 50,
            'status' => InvoiceStatusEnum::DUE,
        ]);
        $service = filterInvoice($this->branch->id, $this->alice->id, [], $this->printing->id);

        $this->actingAs($this->branchAdmin)
            ->get(route('invoices.index', ['branch_service_id' => $this->printing->id]))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)
                ->where('items.data.0.invoiceNumber', $service->invoice_number));
    });

    // ── التراكب ──────────────────────────────────────────────────

    it('stacks employee, service, payment method, unsettled and a date range', function () {
        $match = filterInvoice($this->branch->id, $this->alice->id, [
            'payment_method_id' => $this->cash->id,
            'status' => InvoiceStatusEnum::PARTIALLY_PAID,
        ], $this->printing->id);

        // كلٌّ من هذه يخالف الفلتر في بندٍ واحد فقط.
        filterInvoice($this->branch->id, $this->bob->id, ['payment_method_id' => $this->cash->id], $this->printing->id);
        filterInvoice($this->branch->id, $this->alice->id, ['payment_method_id' => $this->card->id], $this->printing->id);
        filterInvoice($this->branch->id, $this->alice->id, ['payment_method_id' => $this->cash->id], $this->design->id);
        filterInvoice($this->branch->id, $this->alice->id, [
            'payment_method_id' => $this->cash->id,
            'status' => InvoiceStatusEnum::PAID,
        ], $this->printing->id);

        $this->actingAs($this->branchAdmin)
            ->get(route('invoices.index', [
                'user_id' => $this->alice->id,
                'branch_service_id' => $this->printing->id,
                'payment_method_id' => $this->cash->id,
                'status' => 'unsettled',
                'date_from' => today()->toDateString(),
                'date_to' => today()->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page->has('items.data', 1)
                ->where('items.data.0.invoiceNumber', $match->invoice_number));
    });

    // ── نطاق القوائم ─────────────────────────────────────────────

    it('never offers a branch admin the employees of another branch', function () {
        $other = Branch::factory()->create();
        $stranger = User::factory()->create(['branch_id' => $other->id, 'name' => 'غريب']);
        $stranger->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->branchAdmin)
            ->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->where(
                'filterOptions.employees',
                fn ($rows) => ! collect($rows)->pluck('name')->contains('غريب')
                    && collect($rows)->pluck('name')->contains('أليس'),
            ));
    });

    it('tells a super-admin which branch each same-named service belongs to (task 101)', function () {
        $superAdmin = User::factory()->create();
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);
        $this->branch->update(['name' => 'الفرع الأول']);
        $template = ServiceTemplate::factory()->create(['name' => 'طباعة جاهزة']);
        $template->branches()->attach($this->branch->id, ['base_commission_pct' => 10, 'is_active' => true]);
        $template->branches()->attach(Branch::factory()->create(['name' => 'الفرع الثاني'])->id, ['base_commission_pct' => 10, 'is_active' => true]);

        $this->actingAs($superAdmin)
            ->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->where(
                'filterOptions.services',
                fn ($rows) => collect($rows)->pluck('name')->intersect(['طباعة جاهزة — الفرع الأول', 'طباعة جاهزة — الفرع الثاني'])->count() === 2,
            ));

        // ومدير الفرع يرى خدمات فرعه وحده، فلا لاحقة.
        $this->actingAs($this->branchAdmin)
            ->get(route('invoices.index'))
            ->assertInertia(fn ($page) => $page->where(
                'filterOptions.services',
                fn ($rows) => collect($rows)->pluck('name')->contains('طباعة جاهزة'),
            ));
    });
});
