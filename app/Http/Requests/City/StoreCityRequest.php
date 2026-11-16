<?php

namespace App\Http\Requests\City;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // تاسك 244: القائمة مشتركة يضيف إليها مديرو الفروع — فلا تتكرّر «الرياض».
            'name' => ['required', 'string', 'max:255', Rule::unique('cities', 'name')],
            'is_active' => ['boolean'],
        ];
    }
}
