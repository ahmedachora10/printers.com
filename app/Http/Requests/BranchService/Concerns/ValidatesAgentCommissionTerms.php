<?php

namespace App\Http\Requests\BranchService\Concerns;

use App\Enums\LineAgentCommissionTypeEnum;
use Illuminate\Validation\Validator;

/** تاسك 153 — قيود نوع عمولة المندوب، مشتركة بين نافذة الخدمة ونافذة المندوب. */
trait ValidatesAgentCommissionTerms
{
    /** @param  callable(array<string, mixed>): bool  $measured  هل خدمة هذا الصفّ بالمتر؟ */
    protected function validateAgentTerms(Validator $validator, callable $measured): void
    {
        foreach ((array) $this->input('commissions') as $i => $row) {
            $type = LineAgentCommissionTypeEnum::tryFrom((string) ($row['commission_type'] ?? ''));

            if ($type === LineAgentCommissionTypeEnum::Percentage && (float) ($row['commission_value'] ?? 0) > 100) {
                $validator->errors()->add("commissions.$i.commission_value", 'النسبة يجب ألا تتجاوز 100%.');
            }

            if ($type === LineAgentCommissionTypeEnum::PerSqm && ! $measured((array) $row)) {
                $validator->errors()->add("commissions.$i.commission_type", 'عمولة وحدة القياس متاحة فقط للخدمات المسعّرة بالمتر.');
            }
        }
    }
}
