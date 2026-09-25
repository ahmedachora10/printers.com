<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * تاسك 125 — «مراجع الحسابات»: مراجعٌ أو أكثر لكل فرع، يطّلع على المبيعات
 * والتقارير في فرعه ولا يطبع ولا يصدّر ولا يعتمد ولا يُنشئ.
 */

function auditorInvoice(Branch $branch, User $user, InvoiceStatusEnum $status = InvoiceStatusEnum::DUE): ServiceInvoice
{
    return ServiceInvoice::create([
        'invoice_number' => 'SINV-'.fake()->unique()->numerify('######'),
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => 100,
        'vat_pct' => 15,
        'vat_amount' => 13.04,
        'total_amount' => 100,
        'employee_commission' => 0,
        'status' => $status,
        'paid_at' => $status === InvoiceStatusEnum::PAID ? now() : null,
    ]);
}

function makeUserWithRole(Roles $role, ?Branch $branch = null): User
{
    $user = User::factory()->create(['branch_id' => $branch?->id]);
    $user->addRole($role->value);

    return $user;
}

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->branch = Branch::factory()->create(['vat_rate_override' => 15]);
    $this->otherBranch = Branch::factory()->create(['vat_rate_override' => 15]);

    $this->auditor = makeUserWithRole(Roles::AUDITOR, $this->branch);
    $employee = makeUserWithRole(Roles::EMPLOYEE, $this->branch);
    $otherEmployee = makeUserWithRole(Roles::EMPLOYEE, $this->otherBranch);

    $this->ownInvoice = auditorInvoice($this->branch, $employee);
    $this->otherInvoice = auditorInvoice($this->otherBranch, $otherEmployee);
});

describe('what the auditor may see', function () {
    it('opens every screen of the client list', function (string $route) {
        $this->actingAs($this->auditor)->get(route($route))->assertOk();
    })->with([
        'invoices.index',
        'invoices.service.review',
        'services.price-list',
        'refunds.index',
        'reports.sales',
        'reports.commissions',
        'reports.agent-commissions',
        'reports.expenses',
        'reports.materials',
        'reports.incentives',
        'shipping.deliveries',
        'reports.daily',
        'analytics.index',
    ]);

    it('sees only its own branch in the invoice list and the review queue', function (string $route) {
        $this->actingAs($this->auditor)->get(route($route))
            ->assertOk()
            ->assertSee($this->ownInvoice->invoice_number)
            ->assertDontSee($this->otherInvoice->invoice_number);
    })->with(['invoices.index', 'invoices.service.review']);

    it('opens an invoice of its branch but not of another', function () {
        $this->actingAs($this->auditor)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $this->ownInvoice->id]))
            ->assertOk();

        $this->actingAs($this->auditor)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $this->otherInvoice->id]))
            ->assertForbidden();
    });

    it('gets no action flag on an invoice it opens', function () {
        $invoice = $this->actingAs($this->auditor)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $this->ownInvoice->id]))
            ->viewData('page')['props']['invoice'];

        foreach (['canRefund', 'canApprovePayment', 'canRecordPayment', 'canDeliver', 'canEdit', 'canEditCustomer', 'canEditPaymentMethod', 'canReturn'] as $flag) {
            expect($invoice[$flag])->toBeFalse("{$flag} should be false for the auditor");
        }
    });

    it('lists exactly the thirteen sidebar items of the client list', function () {
        $groups = $this->actingAs($this->auditor)->get(route('reports.sales'))
            ->viewData('page')['props']['auth']['sidebarItems'];

        $titles = collect($groups)->flatMap(fn ($group) => collect($group['items'])->pluck('title'))->all();

        expect($titles)->toEqualCanonicalizing([
            'الفواتير', 'عروض الاسعار', 'قائمة الأسعار', 'المرتجعات',
            'تقرير المبيعات', 'تقرير العمولات', 'عمولات المناديب', 'تقرير المصروفات',
            'استهلاك الخامات', 'الحوافز والخصومات', 'كشف التوصيل', 'التقرير اليومي',
            'التحليلات المتقدمة',
        ]);
    });
});

describe('what the auditor may not do', function () {
    it('cannot print, export, download or reach other screens', function (string $url) {
        $this->actingAs($this->auditor)->get($url)->assertForbidden();
    })->with([
        'print' => fn () => route('invoices.print', ['type' => 'service', 'id' => $this->ownInvoice->id]),
        'legacy print' => fn () => route('pos.service.print', $this->ownInvoice->id),
        'delivery note' => fn () => route('invoices.service.delivery-note', $this->ownInvoice->id),
        'receipt' => fn () => route('invoices.receipt', ['type' => 'service', 'id' => $this->ownInvoice->id]),
        'sales export' => fn () => route('reports.sales.export'),
        'receipts zip' => fn () => route('reports.sales.receipts'),
        'daily export' => fn () => route('reports.daily.export'),
        'expenses export' => fn () => route('reports.expenses.export'),
        'materials export' => fn () => route('reports.materials.export'),
        'agent commissions export' => fn () => route('reports.agent-commissions.export'),
        'commissions export' => fn () => route('reports.commissions.export'),
        'incentives export' => fn () => route('reports.incentives.export'),
        'product pos' => fn () => route('pos.product.create'),
        'service pos' => fn () => route('pos.service.create'),
        'refund lookup' => fn () => route('refunds.lookup'),
        'expenses' => fn () => route('expenses.index'),
        'incentives' => fn () => route('incentives.index'),
        'users' => fn () => route('users.index'),
        'customers' => fn () => route('customers.index'),
    ]);

    it('cannot settle, cancel, collect or refund', function (string $method, string $url) {
        $this->actingAs($this->auditor)->{$method}($url, [])->assertForbidden();

        expect($this->ownInvoice->fresh()->status)->toBe(InvoiceStatusEnum::DUE);
    })->with([
        'approve' => ['patch', fn () => route('invoices.service.pay', $this->ownInvoice->id)],
        'cancel' => ['patch', fn () => route('invoices.service.cancel', $this->ownInvoice->id)],
        'deposit' => ['post', fn () => route('invoices.payments.store', ['type' => 'service', 'id' => $this->ownInvoice->id])],
        'refund' => ['post', fn () => route('refunds.store')],
        'settlement file' => ['post', fn () => route('reports.sales.settlement-file.store')],
    ]);

    it('lands on the sales report instead of the dashboard', function () {
        $this->post(route('login'), ['username' => $this->auditor->username, 'password' => 'password'])
            ->assertRedirect(route('reports.sales', absolute: false));

        $this->actingAs($this->auditor)->get(route('dashboard'))->assertRedirect(route('reports.sales'));
    });
});

describe('assigning the auditor', function () {
    function auditorPayload(array $overrides = []): array
    {
        return [
            'name' => 'مراجع',
            'username' => 'auditor'.fake()->unique()->numberBetween(1, 99999),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => Roles::AUDITOR->value,
            'is_active' => true,
            ...$overrides,
        ];
    }

    beforeEach(function () {
        $this->superAdmin = makeUserWithRole(Roles::SUPER_ADMIN);
    });

    it('lets a branch admin appoint the auditor of its own branch', function () {
        $admin = makeUserWithRole(Roles::BRANCH_ADMIN);
        $this->otherBranch->update(['owner_id' => $admin->id]);

        $this->actingAs($admin)->post(route('users.store'), auditorPayload(['username' => 'otheraudit']))
            ->assertSessionHasNoErrors();

        $user = User::where('username', 'otheraudit')->firstOrFail();
        expect($user->hasRole(Roles::AUDITOR->value))->toBeTrue()
            ->and((int) $user->branch_id)->toBe($this->otherBranch->id);
    });

    it('allows several auditors on the same branch', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('users.store'), auditorPayload(['branch_id' => $this->branch->id]))
            ->assertSessionHasNoErrors();

        expect(User::whereHasRole(Roles::AUDITOR->value)->where('branch_id', $this->branch->id)->count())->toBe(2);
    });

    it('refuses an auditor without a branch', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('users.store'), auditorPayload())
            ->assertSessionHasErrors('branch_id');
    });

    it('keeps the existing auditor editable on its own branch', function () {
        $this->actingAs($this->superAdmin)
            ->put(route('users.update', $this->auditor), auditorPayload([
                'username' => $this->auditor->username,
                'email' => $this->auditor->email,
                'password' => null,
                'password_confirmation' => null,
                'branch_id' => $this->branch->id,
            ]))
            ->assertSessionHasNoErrors();
    });
});
