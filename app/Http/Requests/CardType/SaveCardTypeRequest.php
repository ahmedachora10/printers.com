<?php

namespace App\Http\Requests\CardType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** تاسك 146 — إضافة/تعديل نوع بطاقة. */
class SaveCardTypeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('card_types', 'name')
                ->whereNull('deleted_at')
                ->ignore($this->route('cardType'))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.unique' => 'يوجد نوع بطاقة بهذا الاسم.'];
    }
}
