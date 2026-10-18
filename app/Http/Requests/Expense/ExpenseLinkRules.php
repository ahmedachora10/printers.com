<?php

namespace App\Http\Requests\Expense;

use App\Enums\ExpenseSourceEnum;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * تاسك 187 — ما يُلحق بالمصروف ويشترك فيه التسجيل والتعديل: الحساب البنكي،
 * المرفقات، والفواتير المربوطة بحصصها.
 */
trait ExpenseLinkRules
{
    /**
     * الحساب إلزاميّ للتحويل ولا مكان له في النقدي — ويقرؤه أيضاً «تسليم مبلغ التوصيل».
     *
     * @return array<int, mixed>
     */
    public static function bankAccountRule(?int $branchId): array
    {
        $transfer = ExpenseSourceEnum::CompanyTransfer->value;

        return ['nullable', "required_if:paid_from,{$transfer}", "prohibited_unless:paid_from,{$transfer}", 'integer',
            Rule::exists('bank_accounts', 'id')->where('branch_id', $branchId)->whereNull('deleted_at')];
    }

    /** @return array<string, mixed> */
    protected function linkRules(?int $branchId): array
    {
        return [
            'bank_account_id' => self::bankAccountRule($branchId),
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            // فواتير خدمات من فرع المصروف نفسه.
            'invoices' => ['nullable', 'array'],
            'invoices.*.id' => ['required', 'integer', 'distinct', Rule::exists('service_invoices', 'id')->where('branch_id', $branchId)->whereNull('deleted_at')],
            'invoices.*.amount' => ['nullable', 'numeric', 'min:0.01'],
        ];
    }

    /**
     * فاتورةٌ واحدة تأخذ المبلغ كاملاً (يضبطه الـAction)؛ أكثر من فاتورة ⇒ مبلغٌ لكلٍّ
     * منها، ومجموعها = إجمالي المصروف.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $invoices = $this->input('invoices', []);

            if ($validator->errors()->isNotEmpty() || ! is_array($invoices) || count($invoices) < 2) {
                return;
            }

            $total = bcmul((string) $this->input('qty'), (string) $this->input('unit_price'), 2);
            $split = array_reduce($invoices, fn (string $sum, $link) => bcadd($sum, (string) ($link['amount'] ?? 0), 2), '0');

            if (bccomp($split, $total, 2) !== 0) {
                $validator->errors()->add('invoices', "مجموع المبالغ الموزّعة ({$split}) لا يساوي إجمالي المصروف ({$total}).");
            }
        }];
    }
}
