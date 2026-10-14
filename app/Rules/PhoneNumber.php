<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * جوال سعودي 05XXXXXXXX أو رقم دولي برمز دولته (+923029216123) — بلا مسافات.
 * الطلب يمرّر القيمة على Customer::normalizePhone() قبل التحقق، فالمسافات و+966
 * و00 مقبولة عند الإدخال.
 */
class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^(05\d{8}|\+[1-9]\d{7,14})$/', $value)) {
            $fail('رقم الجوال غير صالح: جوال سعودي 05XXXXXXXX، أو رقم دولي يبدأ بـ + ورمز الدولة.');
        }
    }
}
