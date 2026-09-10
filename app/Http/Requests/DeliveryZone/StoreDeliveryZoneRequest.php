<?php

namespace App\Http\Requests\DeliveryZone;

use App\Enums\DeliveryZoneTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $user = $this->user();

        if (! $user?->roleName->isSuperAdmin()) {
            $this->merge(['branch_id' => $user?->branchId]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isDistance = $this->input('type') === DeliveryZoneTypeEnum::Distance->value;

        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'type' => ['required', Rule::enum(DeliveryZoneTypeEnum::class)],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('delivery_zones', 'name')
                    ->whereNull('deleted_at')
                    ->where('branch_id', $this->input('branch_id')),
            ],
            // الحدّ الأدنى يلزم شريحة المسافة وحدها؛ وصفر كيلومتر قيمةٌ صحيحة
            // (الشريحة الأولى تبدأ من الفرع نفسه) فالقاعدة `min:0` لا `gt:0`.
            'from_km' => [Rule::requiredIf($isDistance), 'nullable', 'numeric', 'min:0', 'max:9999'],
            // الفراغ = شريحة مفتوحة «أكثر من كذا»، وهو المقصود لا نقصٌ في النموذج.
            'to_km' => ['nullable', 'numeric', 'gt:from_km', 'max:9999'],
            // السعر شاملٌ للضريبة كسائر أسعار النظام (تاسك 37)، والصفر مسموح:
            // التوصيل المجّاني عرضٌ ترويجيّ يبقى معه اسم السائق مسجّلاً.
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'اختر الفرع.',
            'name.required' => 'اسم الشريحة أو الحي مطلوب.',
            'name.unique' => 'توجد شريحة بهذا الاسم في هذا الفرع بالفعل.',
            'from_km.required' => 'أدخل بداية المسافة بالكيلومتر.',
            'to_km.gt' => 'نهاية المسافة يجب أن تكون أكبر من بدايتها. اتركها فارغة للشريحة المفتوحة.',
            'price.required' => 'أدخل سعر التوصيل.',
            'price.min' => 'السعر لا يقبل قيمة سالبة.',
        ];
    }
}
