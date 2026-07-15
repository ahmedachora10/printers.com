<?php

use App\Enums\IncentivePlanStatusEnum;
use App\Enums\Roles;
use App\Models\BonusPayment;
use App\Models\Branch;
use App\Models\IncentivePlan;
use App\Models\ServiceInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A non-cancelled service invoice attributed to an employee, dated within the
 * given month so it counts toward that month's incentive target.
 */
function salesInvoice(int $branchId, int $userId, float $total, ?int $year = null, ?int $month = null): ServiceInvoice
{
    $date = ($year && $month) ? now()->setDate($year, $month, 15) : now();

    return ServiceInvoice::create([
        'invoice_number' => 'SINV-'.fake()->unique()->numerify('######'),
        'branch_id' => $branchId,
        'user_id' => $userId,
        'subtotal' => $total,
        'coupon_discount' => 0,
        'agent_discount' => 0,
        'vat_pct' => 15,
        'vat_amount' => 0,
        'total_amount' => $total,
        'employee_commission' => 0,
        'status' => 'paid',
        'paid_at' => $date,
        'created_at' => $date,
    ]);
}

describe('Incentives', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);
        $this->actingAs($this->branchAdmin);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
    });

    it('allows branch-admin to view the incentives page', function () {
        IncentivePlan::factory()->create(['user_id' => $this->employee->id, 'branch_id' => $this->branch->id]);

        $this->get(route('incentives.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('incentives/index')
                ->has('plans.data', 1)
                ->has('plans.meta.total'));
    });

    it('prevents accountant from viewing the incentives page', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        $this->get(route('incentives.index'))->assertForbidden();
    });

    it('creates a plan and seeds achieved sales from existing invoices', function () {
        salesInvoice($this->branch->id, $this->employee->id, 600, now()->year, now()->month);
        salesInvoice($this->branch->id, $this->employee->id, 700, now()->year, now()->month);

        $this->post(route('incentives.store'), [
            'user_id' => $this->employee->id,
            'period_month' => now()->month,
            'period_year' => now()->year,
            'target_amount' => 1000,
            'bonus_type' => 'fixed',
            'bonus_value' => 200,
        ])->assertRedirect(route('incentives.index'));

        $plan = IncentivePlan::firstOrFail();
        expect((float) $plan->achieved_amount)->toBe(1300.0)
            ->and($plan->status)->toBe(IncentivePlanStatusEnum::Achieved)
            ->and($plan->branch_id)->toBe($this->branch->id);
    });

    it('rejects a duplicate plan for the same employee and month', function () {
        IncentivePlan::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'period_month' => 5,
            'period_year' => 2026,
        ]);

        $this->post(route('incentives.store'), [
            'user_id' => $this->employee->id,
            'period_month' => 5,
            'period_year' => 2026,
            'target_amount' => 1000,
            'bonus_type' => 'fixed',
            'bonus_value' => 100,
        ])->assertSessionHasErrors('user_id');

        expect(IncentivePlan::count())->toBe(1);
    });

    it('marks a plan missed once the period has passed without hitting target', function () {
        $this->post(route('incentives.store'), [
            'user_id' => $this->employee->id,
            'period_month' => 1,
            'period_year' => 2025,
            'target_amount' => 5000,
            'bonus_type' => 'fixed',
            'bonus_value' => 100,
        ])->assertRedirect();

        expect(IncentivePlan::firstOrFail()->status)->toBe(IncentivePlanStatusEnum::Missed);
    });

    it('recalculates achieved sales and status', function () {
        $plan = IncentivePlan::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'period_month' => now()->month,
            'period_year' => now()->year,
            'target_amount' => 500,
            'achieved_amount' => 0,
            'status' => IncentivePlanStatusEnum::Active,
        ]);

        salesInvoice($this->branch->id, $this->employee->id, 800, now()->year, now()->month);

        $this->post(route('incentives.recalculate'))->assertRedirect();

        $plan->refresh();
        expect((float) $plan->achieved_amount)->toBe(800.0)
            ->and($plan->status)->toBe(IncentivePlanStatusEnum::Achieved);
    });

    it('pays a fixed bonus on an achieved plan and freezes it', function () {
        salesInvoice($this->branch->id, $this->employee->id, 1200, now()->year, now()->month);

        $plan = IncentivePlan::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'period_month' => now()->month,
            'period_year' => now()->year,
            'target_amount' => 1000,
            'bonus_type' => 'fixed',
            'bonus_value' => 300,
            'achieved_amount' => 1200,
            'status' => IncentivePlanStatusEnum::Achieved,
        ]);

        $this->post(route('incentives.pay', $plan))->assertRedirect(route('incentives.index'));

        $this->assertDatabaseHas('bonus_payments', [
            'incentive_plan_id' => $plan->id,
            'amount' => 300,
            'paid_by' => $this->branchAdmin->id,
        ]);
        expect($plan->fresh()->status)->toBe(IncentivePlanStatusEnum::Paid);
    });

    it('computes a percentage bonus from achieved sales', function () {
        salesInvoice($this->branch->id, $this->employee->id, 2000, now()->year, now()->month);

        $plan = IncentivePlan::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'period_month' => now()->month,
            'period_year' => now()->year,
            'target_amount' => 1000,
            'bonus_type' => 'percentage',
            'bonus_value' => 10,
            'achieved_amount' => 2000,
            'status' => IncentivePlanStatusEnum::Achieved,
        ]);

        $this->post(route('incentives.pay', $plan))->assertRedirect();

        // 10% of 2000 achieved sales.
        expect((float) BonusPayment::firstOrFail()->amount)->toBe(200.0);
    });

    it('refuses to pay a bonus when the target is not met', function () {
        $plan = IncentivePlan::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'period_month' => now()->month,
            'period_year' => now()->year,
            'target_amount' => 5000,
            'achieved_amount' => 0,
            'status' => IncentivePlanStatusEnum::Active,
        ]);

        $this->post(route('incentives.pay', $plan))->assertSessionHasErrors('incentive_plan_id');

        expect(BonusPayment::count())->toBe(0);
    });

    it('forbids editing a paid plan', function () {
        $plan = IncentivePlan::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'status' => IncentivePlanStatusEnum::Paid,
        ]);

        $this->put(route('incentives.update', $plan), [
            'user_id' => $this->employee->id,
            'period_month' => $plan->period_month,
            'period_year' => $plan->period_year,
            'target_amount' => 999,
            'bonus_type' => 'fixed',
            'bonus_value' => 1,
        ])->assertForbidden();
    });

    it('prevents a branch-admin from filing a plan for another branch employee', function () {
        $otherBranch = Branch::factory()->create();
        $otherEmployee = User::factory()->create(['branch_id' => $otherBranch->id]);
        $otherEmployee->addRole(Roles::EMPLOYEE->value);

        $this->post(route('incentives.store'), [
            'user_id' => $otherEmployee->id,
            'period_month' => now()->month,
            'period_year' => now()->year,
            'target_amount' => 1000,
            'bonus_type' => 'fixed',
            'bonus_value' => 100,
        ])->assertForbidden();

        expect(IncentivePlan::count())->toBe(0);
    });
});
