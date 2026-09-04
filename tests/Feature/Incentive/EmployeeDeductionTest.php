<?php

use App\Enums\DeductionReasonEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\EmployeeDeduction;
use App\Models\User;
use App\Notifications\DeductionRecordedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * تاسك 74: حسم الإدارة على الموظف — بسببه وقيمته، وبندٌ مستقلّ لا يُعيد كتابة
 * رقمٍ منشور.
 */
describe('Employee deductions', function () {
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

    it('lets a branch admin record a deduction on their own employee', function () {
        Notification::fake();

        $this->post(route('employee-deductions.store'), [
            'user_id' => $this->employee->id,
            'amount' => 150.5,
            'reason' => DeductionReasonEnum::Performance->value,
            'notes' => 'تكرار التأخر عن التسليم',
        ])->assertRedirect();

        $this->assertDatabaseHas('employee_deductions', [
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 150.5,
            'reason' => 'performance',
            'deducted_by' => $this->branchAdmin->id,
        ]);

        Notification::assertSentTo($this->employee, DeductionRecordedNotification::class);
    });

    it('forbids a deduction on an employee of another branch', function () {
        $otherBranch = Branch::factory()->create();
        $stranger = User::factory()->create(['branch_id' => $otherBranch->id]);
        $stranger->addRole(Roles::EMPLOYEE->value);

        $this->post(route('employee-deductions.store'), [
            'user_id' => $stranger->id,
            'amount' => 100,
            'reason' => DeductionReasonEnum::ExecutionError->value,
        ])->assertForbidden();

        expect(EmployeeDeduction::count())->toBe(0);
    });

    it('keeps the accountant and the employee out of the whole screen', function (string $role) {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->addRole($role);

        $this->actingAs($user)->get(route('incentives.index'))->assertForbidden();

        $this->actingAs($user)->post(route('employee-deductions.store'), [
            'user_id' => $this->employee->id,
            'amount' => 100,
            'reason' => DeductionReasonEnum::Performance->value,
        ])->assertForbidden();

        expect(EmployeeDeduction::count())->toBe(0);
    })->with([Roles::ACCOUNTANT->value, Roles::EMPLOYEE->value]);

    it('refuses «other» without the note that explains it', function () {
        $this->post(route('employee-deductions.store'), [
            'user_id' => $this->employee->id,
            'amount' => 100,
            'reason' => DeductionReasonEnum::Other->value,
        ])->assertSessionHasErrors('reason_note');

        expect(EmployeeDeduction::count())->toBe(0);
    });

    it('accepts «other» once explained', function () {
        $this->post(route('employee-deductions.store'), [
            'user_id' => $this->employee->id,
            'amount' => 100,
            'reason' => DeductionReasonEnum::Other->value,
            'reason_note' => 'إتلاف خامة بغير قصد',
        ])->assertRedirect();

        $deduction = EmployeeDeduction::firstOrFail();

        expect($deduction->reason)->toBe(DeductionReasonEnum::Other)
            ->and($deduction->reasonLabel())->toContain('إتلاف خامة بغير قصد');
    });

    it('refuses an amount that is not positive', function () {
        $this->post(route('employee-deductions.store'), [
            'user_id' => $this->employee->id,
            'amount' => 0,
            'reason' => DeductionReasonEnum::Performance->value,
        ])->assertSessionHasErrors('amount');

        expect(EmployeeDeduction::count())->toBe(0);
    });

    it('never touches the commission ledger', function () {
        $before = CommissionLedger::count();

        $this->post(route('employee-deductions.store'), [
            'user_id' => $this->employee->id,
            'amount' => 250,
            'reason' => DeductionReasonEnum::NonCompliance->value,
        ])->assertRedirect();

        expect(CommissionLedger::count())->toBe($before)
            ->and(EmployeeDeduction::count())->toBe(1);
    });

    it('lists the branch deductions on the incentives screen, and nobody else\'s', function () {
        EmployeeDeduction::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'amount' => 80,
            'deducted_by' => $this->branchAdmin->id,
        ]);

        $otherBranch = Branch::factory()->create();
        EmployeeDeduction::factory()->create([
            'branch_id' => $otherBranch->id,
            'amount' => 999,
            'deducted_by' => $this->branchAdmin->id,
        ]);

        $this->get(route('incentives.index'))
            ->assertInertia(fn ($page) => $page
                ->has('deductions.data', 1)
                // Inertia يرمّز 80.00 رقماً صحيحاً في JSON، فالمقارنة على 80 لا 80.0.
                ->where('deductions.data.0.amount', 80)
                ->where('deductionsTotal', 80)
            );
    });

    /** ملاحظات العميل: «إمكانية حذف الخصومات» — حسمٌ سُجّل خطأً يُلغى بحذفه. */
    describe('deleting a deduction', function () {
        it('lets a branch admin delete a deduction, softly, and drops it from the screen', function () {
            $deduction = EmployeeDeduction::factory()->create([
                'user_id' => $this->employee->id,
                'branch_id' => $this->branch->id,
                'amount' => 120,
                'deducted_by' => $this->branchAdmin->id,
            ]);

            $this->delete(route('employee-deductions.destroy', $deduction))->assertRedirect();

            expect(EmployeeDeduction::count())->toBe(0)
                ->and(EmployeeDeduction::withTrashed()->count())->toBe(1);

            $this->get(route('incentives.index'))
                ->assertInertia(fn ($page) => $page
                    ->has('deductions.data', 0)
                    ->where('deductionsTotal', 0)
                );
        });

        it('forbids deleting a deduction of another branch', function () {
            $otherBranch = Branch::factory()->create();
            $deduction = EmployeeDeduction::factory()->create([
                'branch_id' => $otherBranch->id,
                'amount' => 999,
                'deducted_by' => $this->branchAdmin->id,
            ]);

            $this->delete(route('employee-deductions.destroy', $deduction))->assertForbidden();

            expect(EmployeeDeduction::count())->toBe(1);
        });

        it('keeps the accountant and the employee from deleting', function (string $role) {
            $deduction = EmployeeDeduction::factory()->create([
                'user_id' => $this->employee->id,
                'branch_id' => $this->branch->id,
                'deducted_by' => $this->branchAdmin->id,
            ]);

            $user = User::factory()->create(['branch_id' => $this->branch->id]);
            $user->addRole($role);

            $this->actingAs($user)
                ->delete(route('employee-deductions.destroy', $deduction))
                ->assertForbidden();

            expect(EmployeeDeduction::count())->toBe(1);
        })->with([Roles::ACCOUNTANT->value, Roles::EMPLOYEE->value]);

        it('hides the deleted deduction from the employee statement and the report', function () {
            $deduction = EmployeeDeduction::factory()->create([
                'user_id' => $this->employee->id,
                'branch_id' => $this->branch->id,
                'amount' => 300,
                'deducted_by' => $this->branchAdmin->id,
            ]);

            $this->delete(route('employee-deductions.destroy', $deduction))->assertRedirect();

            $this->actingAs($this->employee)
                ->get(route('my-incentives.index'))
                ->assertInertia(fn ($page) => $page
                    ->has('deductions.data', 0)
                    ->where('totals.deductions', 0)
                );

            $this->actingAs($this->branchAdmin)
                ->get(route('reports.incentives'))
                ->assertInertia(fn ($page) => $page->has('deductions', 0));
        });
    });
});
