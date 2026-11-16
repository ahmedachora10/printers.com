<?php

namespace App\Http\Requests\BranchService;

use App\Actions\BranchService\BulkUpsertBranchServicesAction;
use App\Models\ServiceTemplate;
use Illuminate\Validation\Rule;

/** قيم الخانات نفسها قواعدُ التعديل الفردي؛ يُضاف إليها الفروع والمجموعات المختارة. */
class BulkUpsertBranchServicesRequest extends UpdateBranchServiceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $template = $this->route('serviceTemplate');

        return [
            ...parent::rules(),
            'branch_ids' => ['required', 'array', 'min:1'],
            // قالبٌ مملوك لفرع (تاسك 45) لا يُربط إلا بفرعه.
            'branch_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('branches', 'id')->when(
                    $template instanceof ServiceTemplate && $template->branch_id !== null,
                    fn ($rule) => $rule->where('id', $template->branch_id),
                ),
            ],
            'fields' => ['present', 'array'],
            'fields.*' => [Rule::in(array_keys(BulkUpsertBranchServicesAction::GROUPS))],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'branch_ids.required' => 'حدّد فرعاً واحداً على الأقل.',
            'branch_ids.*.exists' => 'أحد الفروع المحدّدة غير متاح لهذه الخدمة.',
        ];
    }
}
