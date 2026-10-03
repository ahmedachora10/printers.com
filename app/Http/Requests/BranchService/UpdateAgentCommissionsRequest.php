<?php

namespace App\Http\Requests\BranchService;

use App\Enums\LineAgentCommissionTypeEnum;
use App\Http\Requests\BranchService\Concerns\ValidatesAgentCommissionTerms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** تاسك 153 — نوع وقيمة عمولة كل مندوب على خدمة فرع؛ النوع null = بلا إعداد. */
class UpdateAgentCommissionsRequest extends FormRequest
{
    use ValidatesAgentCommissionTerms;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branchId = $this->route('branchService')->branch_id;

        return [
            'commissions' => ['present', 'array'],
            'commissions.*.agent_id' => [
                'required',
                // الربط بالفرع في agent_branch لا users.branch_id (تاسك 20-د).
                Rule::exists('agent_branch', 'agent_id')->where('branch_id', $branchId),
            ],
            'commissions.*.commission_type' => ['nullable', Rule::enum(LineAgentCommissionTypeEnum::class)],
            'commissions.*.commission_value' => ['required_with:commissions.*.commission_type', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $measured = $this->route('branchService')->pricing_type?->isMeasured() === true;
            $this->validateAgentTerms($validator, fn () => $measured);
        }];
    }
}
