<?php

use App\Enums\DeductionReasonEnum;
use App\Enums\IncentiveBonusTypeEnum;
use App\Enums\IncentivePlanStatusEnum;
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
 * تقرير الحوافز والخصومات: القراءة المجمَّعة للبندين معاً، وحدودُ من يقرؤها.
 */
describe('Incentive & deduction report', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->branchAdmin);
    });

    it('sums bonuses and deductions per employee and nets them', function () {
        $plan = IncentivePlan::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'period_month' => now()->month,
            'period_year' => now()->year,
            'target_amount' => 10000,
            'achieved_amount' => 12000,
            'bonus_type' => IncentiveBonusTypeEnum::Fixed,
            'bonus_value' => 800,
            'status' => IncentivePlanStatusEnum::Paid,
        ]);

        BonusPayment::factory()->create([
            'incentive_plan_id' => $plan->id,
            'paid_by' => $this->branchAdmin->id,
            'amount' => 800,
            'paid_at' => now(),
        ]);

        EmployeeDeduction::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 200,
            'reason' => DeductionReasonEnum::Performance,
            'deducted_by' => $this->branchAdmin->id,
            'deducted_at' => now(),
        ]);

        $this->get(route('reports.incentives'))
            ->assertOk()
            ->assertInertia(
                fn($page) => $page
                    ->component('reports/incentives/index')
                    ->where('totals.bonusEarned', 800)
                    ->where('totals.bonusPaid', 800)
                    ->where('totals.deductions', 200)
                    // الصافي على المصروف لا على المستحق.
                    ->where('totals.net', 600)
                    ->where('summary.0.userId', $this->employee->id)
                    ->where('summary.0.deductionCount', 1)
                    ->where('byReason.0.reason', DeductionReasonEnum::Performance->value)
            );
    });

    it('keeps an employee with a deduction but no plan in the report', function () {
        EmployeeDeduction::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 120,
            'deducted_by' => $this->branchAdmin->id,
            'deducted_at' => now(),
        ]);

        $this->get(route('reports.incentives'))
            ->assertOk()
            ->assertInertia(
                fn($page) => $page
                    ->where('totals.employeeCount', 1)
                    ->where('totals.planCount', 0)
                    ->where('summary.0.deductions', 120)
                    ->where('summary.0.net', -120)
            );
    });

    it('opens on the current month and honours an explicit range', function () {
        // خطةُ الشهر الماضي وحسمُه: خارج المدى الافتراضي، داخل المدى الموسَّع.
        IncentivePlan::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'period_month' => now()->subMonthNoOverflow()->month,
            'period_year' => now()->subMonthNoOverflow()->year,
            'target_amount' => 5000,
            'achieved_amount' => 5000,
            'bonus_type' => IncentiveBonusTypeEnum::Fixed,
            'bonus_value' => 300,
        ]);

        EmployeeDeduction::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 75,
            'deducted_by' => $this->branchAdmin->id,
            'deducted_at' => now()->subMonthNoOverflow(),
        ]);

        $this->get(route('reports.incentives'))
            ->assertOk()
            ->assertInertia(fn($page) => $page->where('totals.employeeCount', 0));

        $this->get(route('reports.incentives', [
            'from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]))
            ->assertOk()
            ->assertInertia(
                fn($page) => $page
                    ->where('totals.planCount', 1)
                    ->where('totals.bonusEarned', 300)
                    ->where('totals.deductions', 75)
            );
    });

    it('never shows another branch to a branch admin', function () {
        $otherBranch = Branch::factory()->create();
        $stranger = User::factory()->create(['branch_id' => $otherBranch->id]);
        $stranger->addRole(Roles::EMPLOYEE->value);

        EmployeeDeduction::factory()->create([
            'user_id' => $stranger->id,
            'branch_id' => $otherBranch->id,
            'amount' => 999,
            'deducted_at' => now(),
        ]);

        $this->get(route('reports.incentives'))
            ->assertOk()
            ->assertInertia(fn($page) => $page->where('totals.deductions', 0));
    });

    it('is closed to accountants and employees', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        $this->actingAs($accountant)->get(route('reports.incentives'))->assertForbidden();
        $this->actingAs($this->employee)->get(route('reports.incentives'))->assertForbidden();
    });

    it('exports an xlsx file', function () {
        IncentivePlan::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'period_month' => now()->month,
            'period_year' => now()->year,
        ]);

        $response = $this->get(route('reports.incentives.export'));

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('.xlsx');
    });
});
