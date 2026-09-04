<?php

namespace App\Http\Controllers;

use App\Actions\Deduction\CreateDeductionAction;
use App\Actions\Deduction\DeleteDeductionAction;
use App\Http\Requests\Deduction\StoreEmployeeDeductionRequest;
use App\Models\EmployeeDeduction;
use App\Models\User;
use App\Notifications\DeductionRecordedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * تاسك 74: سجلّ حسومات الموظفين، معروضاً تحت بند الحوافز والمكافآت.
 *
 * القراءة تعيش في `IncentiveController::index` مع الخطط (شاشةٌ واحدة بتبويبين)،
 * فليس هنا إلا الكتابة. ولا `update`: القيد لا يُعاد كتابته بعد الإدراج — أمّا
 * `destroy` فحذفٌ soft، إلغاءً لحسمٍ سُجّل خطأً.
 */
class EmployeeDeductionController extends Controller
{
    public function store(StoreEmployeeDeductionRequest $request, CreateDeductionAction $action): RedirectResponse
    {
        Gate::authorize('create', EmployeeDeduction::class);

        $data = $request->validated();
        $employee = User::query()->findOrFail((int) $data['user_id']);

        Gate::authorize('applyTo', [EmployeeDeduction::class, $employee]);

        $branchId = $employee->getAttributes()['branch_id'] ?? null;

        abort_if($branchId === null, 422, 'الموظف غير مرتبط بفرع.');

        $deduction = $action->handle([...$data, 'branch_id' => (int) $branchId]);

        $employee->notify(new DeductionRecordedNotification($deduction));

        return back(fallback: route('incentives.index'))->with('success', 'تم تسجيل الحسم بنجاح');
    }

    public function destroy(EmployeeDeduction $employeeDeduction, DeleteDeductionAction $action): RedirectResponse
    {
        Gate::authorize('delete', $employeeDeduction);

        $action->handle($employeeDeduction);

        return back(fallback: route('incentives.index'))->with('success', 'تم حذف الحسم بنجاح');
    }
}
