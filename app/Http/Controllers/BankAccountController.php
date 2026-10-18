<?php

namespace App\Http\Controllers;

use App\Actions\BankAccount\SaveBankAccountAction;
use App\Http\Requests\BankAccount\SaveBankAccountRequest;
use App\Models\BankAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * تاسك 187 — حسابات الشركة البنكية (تبويب في الإعدادات). الحذف ناعم: المصروفات
 * القديمة تبقى تحمل اسم حسابها.
 */
class BankAccountController extends Controller
{
    public function store(SaveBankAccountRequest $request, SaveBankAccountAction $action): RedirectResponse
    {
        Gate::authorize('create', BankAccount::class);

        $action->handle($request->validated());

        return back()->with('success', 'تم إضافة الحساب البنكي');
    }

    public function update(SaveBankAccountRequest $request, BankAccount $bankAccount, SaveBankAccountAction $action): RedirectResponse
    {
        Gate::authorize('update', $bankAccount);

        $action->handle($request->validated(), $bankAccount);

        return back()->with('success', 'تم تحديث الحساب البنكي');
    }

    public function destroy(BankAccount $bankAccount): RedirectResponse
    {
        Gate::authorize('delete', $bankAccount);

        $bankAccount->delete();

        return back()->with('success', 'تم حذف الحساب البنكي');
    }
}
