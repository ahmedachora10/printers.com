<?php

namespace App\Http\Requests\Coupon;

use App\Enums\CouponDiscountTypeEnum;
use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCouponRequest extends FormRequest
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

        /** @var Coupon $coupon */
        $coupon = $this->route('coupon');
        $this->merge(['branch_id' => $coupon->branch_id]);
    }

    public function rules(): array
    {
        /** @var Coupon $coupon */
        $coupon   = $this->route('coupon');
        $branchId = $coupon->branch_id;

        return [
            'branch_id'      => ['required', 'exists:branches,id'],
            'code'           => [
                'required',
                'string',
                'max:100',
                Rule::unique('coupons', 'code')
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->ignore($coupon->id),
            ],
            'discount_type'  => ['required', Rule::enum(CouponDiscountTypeEnum::class)],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'capacity'       => ['nullable', 'integer', 'min:1'],
            'expires_at'     => ['nullable', 'date'],
            'is_active'      => ['boolean'],
        ];
    }
}
