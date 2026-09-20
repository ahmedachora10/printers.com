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

    // تاسك 126 — «إضافة زر التعديل عند تسجيل حسم».
    describe('editing', function () {
        beforeEach(function () {
            $this->deduction = EmployeeDeduction::factory()->create([
                'user_id' => $this->employee->id,
                'branch_id' => $this->branch->id,
                'amount' => 300,
                'reason' => DeductionReasonEnum::Performance,
                'deducted_by' => $this->branchAdmin->id,
                'deducted_at' => now()->subDays(3),
            ]);
        });

        it('lets a branch admin correct a deduction in their own branch', function () {
            $this->patch(route('employee-deductions.update', $this->deduction), [
                'amount' => 150,
                'reason' => DeductionReasonEnum::Other->value,
                'reason_note' => 'تصحيح المبلغ بعد المراجعة',
                'deducted_at' => '2026-09-01',
                'notes' => 'صُحِّح',
            ])->assertRedirect();

            $fresh = $this->deduction->fresh();

            expect((float) $fresh->amount)->toBe(150.0)
                ->and($fresh->reason)->toBe(DeductionReasonEnum::Other)
                ->and($fresh->reason_note)->toBe('تصحيح المبلغ بعد المراجعة')
                ->and($fresh->deducted_at->toDateString())->toBe('2026-09-01')
                // الموظف المحسوم عليه لا يُغيَّر: ليس في الطلب أصلاً.
                ->and($fresh->user_id)->toBe($this->employee->id);
        });

        it('writes the change to the activity log and shows it on the screen', function () {
            $this->patch(route('employee-deductions.update', $this->deduction), [
                'amount' => 150,
                'reason' => DeductionReasonEnum::Performance->value,
                'deducted_at' => $this->deduction->deducted_at->toDateString(),
            ])->assertRedirect();

            $activity = $this->deduction->activities()->where('event', 'updated')->firstOrFail();

            expect((float) $activity->properties['old']['amount'])->toBe(300.0)
                ->and((float) $activity->properties['attributes']['amount'])->toBe(150.0)
                ->and($activity->causer_id)->toBe($this->branchAdmin->id);

            $this->get(route('incentives.index'))
                ->assertInertia(fn ($page) => $page
                    ->has('deductions.data.0.history', 1)
                    ->where('deductions.data.0.history.0.changes.0.label', 'القيمة')
                    ->where('deductions.data.0.history.0.changes.0.old', '300.00')
                    ->where('deductions.data.0.history.0.changes.0.new', '150.00'));
        });

        it('forbids a branch admin from editing a deduction in another branch', function () {
            $otherAdmin = User::factory()->create();
            $otherAdmin->addRole(Roles::BRANCH_ADMIN->value);
            $otherBranch = Branch::factory()->create(['owner_id' => $otherAdmin->id]);
            $otherAdmin->update(['branch_id' => $otherBranch->id]);

            $this->actingAs($otherAdmin)
                ->patch(route('employee-deductions.update', $this->deduction), [
                    'amount' => 1,
                    'reason' => DeductionReasonEnum::Performance->value,
                    'deducted_at' => '2026-09-01',
                ])
                ->assertForbidden();

            expect((float) $this->deduction->fresh()->amount)->toBe(300.0);
        });

        it('refuses «other» with no explanation', function () {
            $this->patch(route('employee-deductions.update', $this->deduction), [
                'amount' => 150,
                'reason' => DeductionReasonEnum::Other->value,
                'deducted_at' => '2026-09-01',
            ])->assertSessionHasErrors('reason_note');
        });
    });
});
