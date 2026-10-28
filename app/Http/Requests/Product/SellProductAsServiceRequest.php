<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class SellProductAsServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // نسبة عمولة الموظفين على المنتج — من الربح، وتبقى قابلةً للتعديل لكل موظف.
            'commission_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
