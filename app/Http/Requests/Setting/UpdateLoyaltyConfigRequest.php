<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoyaltyConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('configure-loyalty') ?? false;
    }

    /**
     * حقلُ مدة انتهاء الصلاحية يصل نصاً فارغاً من النموذج حين يتركه المستخدم
     * خالياً، ومعناه «بلا انتهاء صلاحية» أي NULL لا صفراً.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('expiry_months') === '') {
            $this->merge(['expiry_months' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // تاسك 52: السوبر أدمن وحده يختار الفرع الذي يُحفظ عليه الإعداد؛
            // ومن سواه يُستبعد الحقل من المُتحقَّق منه فيعود الخادم إلى فرعه.
            'branch_id' => [
                Rule::excludeIf(! $this->actorIsSuperAdmin()),
                'required',
                'integer',
                Rule::exists('branches', 'id')->whereNull('deleted_at'),
            ],
            'is_active' => ['required', 'boolean'],
            'earning_rate' => ['required', 'numeric', 'min:0', 'max:9999'],
            'redemption_rate' => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'min_redemption_points' => ['required', 'integer', 'min:0'],
            // فارغاً = بلا انتهاء صلاحية.
            'expiry_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'bronze_threshold' => ['required', 'numeric', 'min:0'],
            'silver_threshold' => ['required', 'numeric', 'min:0', 'gte:bronze_threshold'],
            'gold_threshold' => ['required', 'numeric', 'min:0', 'gte:silver_threshold'],
            'bronze_discount_pct' => ['required', 'numeric', 'between:0,100'],
            'silver_discount_pct' => ['required', 'numeric', 'between:0,100'],
            'gold_discount_pct' => ['required', 'numeric', 'between:0,100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'اختر الفرع الذي تُحفظ عليه إعدادات برنامج الولاء.',
            'branch_id.exists' => 'الفرع المختار غير موجود.',
            'redemption_rate.min' => 'معدل الاستبدال يجب أن يكون أكبر من صفر.',
            'expiry_months.min' => 'مدة انتهاء الصلاحية شهر واحد على الأقل.',
            'expiry_months.max' => 'مدة انتهاء الصلاحية 120 شهراً كحد أقصى.',
            'silver_threshold.gte' => 'حد الفئة الفضية يجب ألا يقل عن حد الفئة البرونزية.',
            'gold_threshold.gte' => 'حد الفئة الذهبية يجب ألا يقل عن حد الفئة الفضية.',
        ];
    }

    private function actorIsSuperAdmin(): bool
    {
        return $this->user()?->roleName?->isSuperAdmin() ?? false;
    }
}
