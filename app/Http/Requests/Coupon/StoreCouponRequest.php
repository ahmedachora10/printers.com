<?php

namespace App\Http\Requests\Coupon;

use App\Enums\CouponDiscountTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtolower($this->input('code'))]);
        }

        $user = Auth::user();

        if ($user->roleName->isBranchAdmin()) {
            $this->merge(['branch_id' => $user->branchId]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branchId = (int) $this->input('branch_id', Auth::user()->branchId);

        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('coupons', 'code')
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at'),
            ],
            'discount_type' => ['required', Rule::enum(CouponDiscountTypeEnum::class)],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'is_active' => ['boolean'],
        ];
    }
}
