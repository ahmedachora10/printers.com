<?php

namespace App\Actions\BankAccount;

use App\Models\BankAccount;

/** تاسك 187 — حفظ حساب بنكي لفرع. */
class SaveBankAccountAction
{
    /** @param array{branch_id: int, name: string, bank_name?: ?string, iban_last4?: ?string} $data */
    public function handle(array $data, ?BankAccount $account = null): BankAccount
    {
        $account ??= new BankAccount;
        $account->fill($data)->save();

        return $account;
    }
}
