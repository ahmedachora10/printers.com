<?php

namespace App\Http\Requests\Customer;

use App\Enums\CustomerTierEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OverrideCustomerTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tier' => ['required', Rule::enum(CustomerTierEnum::class)],
            // تصحيحٌ اختياري للإنفاق التراكمي يرافق التنزيل: تركُه فارغاً يُبقيه
            // كما هو، فيعود المحرّك ويرقّي العميل عند أول فاتورة مسدَّدة.
            'cumulative_spend' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tier.required' => 'اختر المستوى.',
            'reason.required' => 'سبب التعديل مطلوب — يُحفظ في سجلّ العميل.',
            'cumulative_spend.numeric' => 'الإنفاق التراكمي يجب أن يكون رقماً.',
        ];
    }
}
