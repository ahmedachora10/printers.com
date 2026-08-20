<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeService(Branch $branch, array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create(array_merge([
        'branch_id' => $branch->id,
        'service_template_id' => $template->id,
        // Deliberately high: commission must come from the per-employee rate, not this.
        'base_commission_pct' => 99,
        'max_discount_pct' => 50,
        'is_tahazir' => false,
        'is_active' => true,
    ], $attrs));

    return BranchService::where('branch_id', $branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

function dueServicePayload(BranchService $service): array
{
    return [
        'status' => 'due',
        'lines' => [
            ['branch_service_id' => $service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
        ],
    ];
}

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

    $this->branchAdmin = User::factory()->create();
    $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
    $this->branch->update(['owner_id' => $this->branchAdmin->id]);

    $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->employee->addRole(Roles::EMPLOYEE->value);

    $this->service = makeService($this->branch);
});

describe('per-employee commission at invoice time', function () {
    it('uses the employee\'s own rate, ignoring the branch base rate', function () {
        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 12,
        ]);

        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), dueServicePayload($this->service))
            ->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();
        $line = $invoice->lines->first();

        // The ledger is written only on approval; the line already carries the
        // computed commission from invoice time.
        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));

        // The rate is 12% of the value net of VAT: 100 / 1.15 = 86.96 → 10.44.
        expect((float) $line->commission_pct)->toBe(12.00)
            ->and((float) $line->commission_amount)->toBe(10.44)
            ->and((float) $invoice->employee_commission)->toBe(10.44)
            ->and((float) CommissionLedger::firstOrFail()->amount)->toBe(10.44);
    });

    it('earns zero commission when the employee has no rate for the service', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.store'), dueServicePayload($this->service))
            ->assertRedirect();

        $invoice = ServiceInvoice::firstOrFail();

        // A zero-rate line still records a ledger row (amount 0) once approved.
        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', payable($invoice)));

        expect((float) $invoice->employee_commission)->toBe(0.00)
            ->and((float) $invoice->lines->first()->commission_amount)->toBe(0.00)
            ->and((float) CommissionLedger::firstOrFail()->amount)->toBe(0.00);
    });

    it('records different commission for two employees on the same service', function () {
        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole(Roles::EMPLOYEE->value);

        UserService::create(['user_id' => $this->employee->id, 'branch_service_id' => $this->service->id, 'commission_override_pct' => 10]);
        UserService::create(['user_id' => $other->id, 'branch_service_id' => $this->service->id, 'commission_override_pct' => 15]);

        $this->actingAs($this->employee)->post(route('pos.service.store'), dueServicePayload($this->service));
        $this->actingAs($other)->post(route('pos.service.store'), dueServicePayload($this->service));

        $byEmployee = ServiceInvoice::where('user_id', $this->employee->id)->firstOrFail();
        $byOther = ServiceInvoice::where('user_id', $other->id)->firstOrFail();

        // 10% and 15% of the net-of-VAT 86.96.
        expect((float) $byEmployee->employee_commission)->toBe(8.70)
            ->and((float) $byOther->employee_commission)->toBe(13.04);
    });
});

describe('managing rates from the user screen', function () {
    it('sets, updates, then clears a rate', function () {
        $this->actingAs($this->branchAdmin);

        $url = route('users.service-commissions.update', $this->employee);

        // Set
        $this->put($url, ['commissions' => [
            ['branch_service_id' => $this->service->id, 'commission_pct' => 8],
        ]])->assertRedirect();
        $this->assertDatabaseHas('user_services', [
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 8,
        ]);

        // Update
        $this->put($url, ['commissions' => [
            ['branch_service_id' => $this->service->id, 'commission_pct' => 20],
        ]])->assertRedirect();
        expect((float) UserService::firstOrFail()->commission_override_pct)->toBe(20.00);

        // Clear (null removes the row → back to 0%)
        $this->put($url, ['commissions' => [
            ['branch_service_id' => $this->service->id, 'commission_pct' => null],
        ]])->assertRedirect();
        expect(UserService::count())->toBe(0);
    });

    it('forbids a branch admin from setting rates for a user in another branch', function () {
        $otherBranch = Branch::factory()->create();
        $outsider = User::factory()->create(['branch_id' => $otherBranch->id]);
        $outsider->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->branchAdmin)
            ->put(route('users.service-commissions.update', $outsider), ['commissions' => []])
            ->assertForbidden();
    });

    it('rejects a branch service that does not belong to the user\'s branch', function () {
        $otherBranch = Branch::factory()->create();
        $foreignService = makeService($otherBranch);

        $this->actingAs($this->branchAdmin)
            ->put(route('users.service-commissions.update', $this->employee), ['commissions' => [
                ['branch_service_id' => $foreignService->id, 'commission_pct' => 10],
            ]])
            ->assertSessionHasErrors('commissions.0.branch_service_id');

        expect(UserService::count())->toBe(0);
    });
});

describe('managing rates from the service screen', function () {
    it('sets per-employee rates for a service', function () {
        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->branchAdmin)
            ->put(route('branch-services.employee-commissions.update', $this->service), ['commissions' => [
                ['user_id' => $this->employee->id, 'commission_pct' => 7],
                ['user_id' => $other->id, 'commission_pct' => 9],
            ]])
            ->assertRedirect();

        $this->assertDatabaseHas('user_services', [
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 7,
        ]);
        $this->assertDatabaseHas('user_services', [
            'user_id' => $other->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 9,
        ]);
    });

    it('lets a super admin set rates for any branch\'s service', function () {
        $superAdmin = User::factory()->create();
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->actingAs($superAdmin)
            ->put(route('branch-services.employee-commissions.update', $this->service), ['commissions' => [
                ['user_id' => $this->employee->id, 'commission_pct' => 11],
            ]])
            ->assertRedirect();

        $this->assertDatabaseHas('user_services', [
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 11,
        ]);
    });

    it('rejects an employee from another branch', function () {
        $otherBranch = Branch::factory()->create();
        $outsider = User::factory()->create(['branch_id' => $otherBranch->id]);
        $outsider->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->branchAdmin)
            ->put(route('branch-services.employee-commissions.update', $this->service), ['commissions' => [
                ['user_id' => $outsider->id, 'commission_pct' => 10],
            ]])
            ->assertSessionHasErrors('commissions.0.user_id');

        expect(UserService::count())->toBe(0);
    });
});
