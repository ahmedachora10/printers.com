<?php

namespace App\Http\Requests\BranchService;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * خامات المخزون التي تستهلكها خدمة الفرع (تاسك 50). القائمة المرسلة هي القائمة
 * كاملة: ما لم يَرِد فيها يُحذف، فحذف خامة هو إرسالها ناقصة.
 */
class UpdateBranchServiceMaterialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branchId = $this->route('branchService')->branch_id;

        return [
            'materials' => ['present', 'array'],
            // منتج من مخزون هذا الفرع وحده — لا تُخصم خامةٌ من مخزون فرع آخر.
            'materials.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at'),
            ],
            'materials.*.qty_per_unit' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'materials.*.product_id.exists' => 'الخامة المحددة ليست من منتجات هذا الفرع.',
            'materials.*.product_id.distinct' => 'لا تُضاف الخامة نفسها مرتين لخدمة واحدة.',
            'materials.*.qty_per_unit.min' => 'كمية الاستهلاك يجب أن تكون أكبر من صفر.',
        ];
    }
}
