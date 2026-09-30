<?php

namespace App\Http\Requests\NetworkDevice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** تاسك 146 — إضافة/تعديل جهاز شبكة. غير المدير العام مثبَّتٌ على فرعه مهما أرسل. */
class SaveNetworkDeviceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $user = $this->user();

        if (! $user->roleName->isSuperAdmin()) {
            $this->merge(['branch_id' => $user->branchId]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branchId = $this->integer('branch_id');

        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            // طريقة «شبكة» يراها فرع الجهاز (عامة أو ملكه).
            'payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')
                ->where('is_network', true)
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))],
            'name' => ['required', 'string', 'max:100'],
            'number' => ['required', 'string', 'max:50', Rule::unique('network_devices', 'number')
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->ignore($this->route('networkDevice'))],
            'is_default' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['number.unique' => 'يوجد جهاز بهذا الرقم في الفرع.'];
    }
}
