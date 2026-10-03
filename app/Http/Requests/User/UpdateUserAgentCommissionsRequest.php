<?php

namespace App\Http\Requests\User;

use App\Enums\LineAgentCommissionTypeEnum;
use App\Http\Requests\BranchService\Concerns\ValidatesAgentCommissionTerms;
use App\Models\BranchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** عمولة المندوب على كل خدمة في فروعه — الوجه الآخر لـ UpdateAgentCommissionsRequest. */
class UpdateUserAgentCommissionsRequest extends FormRequest
{
    use ValidatesAgentCommissionTerms;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branchIds = $this->route('user')->agentBranchIdsManagedBy($this->user());

        return [
            'commissions' => ['present', 'array'],
            'commissions.*.branch_service_id' => [
                'required',
                Rule::exists('branch_services', 'id')->whereIn('branch_id', $branchIds),
            ],
            'commissions.*.commission_type' => ['nullable', Rule::enum(LineAgentCommissionTypeEnum::class)],
            'commissions.*.commission_value' => ['required_with:commissions.*.commission_type', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $measuredIds = BranchService::query()
                ->whereIn('id', collect((array) $this->input('commissions'))->pluck('branch_service_id')->filter())
                ->get()
                ->filter(fn (BranchService $s) => $s->pricing_type?->isMeasured() === true)
                ->modelKeys();

            $this->validateAgentTerms($validator, fn (array $row) => in_array((int) ($row['branch_service_id'] ?? 0), $measuredIds, true));
        }];
    }
}
