<?php

use App\Enums\DeductionReasonEnum;
use App\Enums\IncentiveBonusTypeEnum;
use App\Enums\Roles;
use App\Models\BonusPayment;
use App\Models\Branch;
use App\Models\EmployeeDeduction;
use App\Models\IncentivePlan;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * «حوافزي وحسوماتي»: الشاشة التي يقرأ فيها الموظف حسمه — الشاشة الإدارية مغلقة
 * عليه، وكان الإشعار وحده يمرّ ثم يُدفن.
 */
describe('My incentives & deductions', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
    });

    it('shows the employee their own plans, deductions and net', function () {
        $plan = IncentivePlan::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'period_month' => now()->month,
            'period_year' => now()->year,
            'target_amount' => 8000,
            'achieved_amount' => 8000,
            'bonus_type' => IncentiveBonusTypeEnum::Fixed,
            'bonus_value' => 500,
        ]);

        BonusPayment::factory()->create([
            'incentive_plan_id' => $plan->id,
            'paid_by' => $this->branchAdmin->id,
            'amount' => 500,
            'paid_at' => now(),
        ]);

        EmployeeDeduction::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 125,
            'reason' => DeductionReasonEnum::NonCompliance,
            'deducted_by' => $this->branchAdmin->id,
            'deducted_at' => now(),
        ]);

        $this->actingAs($this->employee)
            ->get(route('my-incentives.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('incentives/my')
                ->where('totals.bonusPaid', 500)
                ->where('totals.deductions', 125)
                ->where('totals.monthDeductions', 125)
                ->where('totals.net', 375)
                ->where('currentPlan.targetAmount', 8000)
                ->has('plans.data', 1)
                ->has('deductions.data', 1)
            );
    });

    it('never leaks another employee\'s rows', function () {
        $colleague = User::factory()->create(['branch_id' => $this->branch->id]);
        $colleague->addRole(Roles::EMPLOYEE->value);

        EmployeeDeduction::factory()->create([
            'user_id' => $colleague->id,
            'branch_id' => $this->branch->id,
            'amount' => 400,
            'deducted_by' => $this->branchAdmin->id,
            'deducted_at' => now(),
        ]);

        $this->actingAs($this->employee)
            ->get(route('my-incentives.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totals.deductions', 0)
                ->has('deductions.data', 0)
            );
    });

    it('is an employee screen only', function () {
        $this->actingAs($this->branchAdmin)->get(route('my-incentives.index'))->assertForbidden();
    });

    it('carries a deduction summary onto the employee dashboard', function () {
        EmployeeDeduction::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 60,
            'reason' => DeductionReasonEnum::ExecutionError,
            'deducted_by' => $this->branchAdmin->id,
            'deducted_at' => now(),
        ]);

        $this->actingAs($this->employee)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('deductions.monthTotal', 60)
                ->where('deductions.monthCount', 1)
                ->where('deductions.lastReason', DeductionReasonEnum::ExecutionError->label())
            );
    });

    it('hides the deduction card from non-employees', function () {
        $this->actingAs($this->branchAdmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('deductions', null));
    });
});
