<?php

namespace App\Http\Requests\DeliveryProvider;

use App\Enums\DeliveryProviderTypeEnum;
use App\Models\DeliveryProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeliveryProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var DeliveryProvider|null $provider */
        $provider = $this->route('deliveryProvider');

        // الفرع لا يُنقل بالتعديل: صفّ الفرع يبقى لفرعه، فالتفرّد يُقاس على
        // نطاق الصفّ نفسه لا على ما قد يُرسل في الطلب.
        $branchId = $provider?->branch_id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('delivery_providers', 'name')
                    ->whereNull('deleted_at')
                    ->ignore($provider?->id)
                    ->where('branch_id', $branchId),
            ],
            'type' => ['required', Rule::enum(DeliveryProviderTypeEnum::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم السائق أو شركة التوصيل مطلوب.',
            'name.unique' => 'يوجد مزوّد توصيل بهذا الاسم في هذا الفرع بالفعل.',
        ];
    }
}
