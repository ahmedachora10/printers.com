<?php

namespace App\Http\Requests\DeliveryZone;

use App\Enums\DeliveryZoneTypeEnum;
use App\Models\DeliveryZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeliveryZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var DeliveryZone|null $zone */
        $zone = $this->route('deliveryZone');

        $branchId = $zone?->branch_id;
        $isDistance = $this->input('type') === DeliveryZoneTypeEnum::Distance->value;

        return [
            'type' => ['required', Rule::enum(DeliveryZoneTypeEnum::class)],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('delivery_zones', 'name')
                    ->whereNull('deleted_at')
                    ->ignore($zone?->id)
                    ->where('branch_id', $branchId),
            ],
            'from_km' => [Rule::requiredIf($isDistance), 'nullable', 'numeric', 'min:0', 'max:9999'],
            'to_km' => ['nullable', 'numeric', 'gt:from_km', 'max:9999'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم الشريحة أو الحي مطلوب.',
            'name.unique' => 'توجد شريحة بهذا الاسم في هذا الفرع بالفعل.',
            'from_km.required' => 'أدخل بداية المسافة بالكيلومتر.',
            'to_km.gt' => 'نهاية المسافة يجب أن تكون أكبر من بدايتها. اتركها فارغة للشريحة المفتوحة.',
            'price.required' => 'أدخل سعر التوصيل.',
        ];
    }
}
