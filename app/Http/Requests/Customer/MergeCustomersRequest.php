<?php

namespace App\Http\Requests\Customer;

use App\Models\CustomerBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergeCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $primary = $this->route('customer');
        $branchId = $this->user()->roleName->isSuperAdmin() ? null : $this->user()->branchId;

        return [
            'secondary_customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
                Rule::notIn([$primary?->id]),
                // مدير الفرع يدمج عميلاً من عملاء فرعه وحده؛ عميلٌ له حساباتٌ في
                // فروعٍ أخرى ينقلها الدمج، فذلك للسوبر أدمن.
                function (string $attribute, mixed $value, \Closure $fail) use ($branchId) {
                    if ($branchId !== null && CustomerBranch::where('customer_id', $value)->pluck('branch_id')->unique()->values()->all() !== [$branchId]) {
                        $fail('هذا العميل مسجّل في فروعٍ أخرى — دمجه للإدارة.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'secondary_customer_id.not_in' => 'لا يمكن دمج العميل مع نفسه.',
        ];
    }
}
