<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLoyaltyConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('configure-loyalty') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'earning_rate' => ['required', 'numeric', 'min:0', 'max:9999'],
            'redemption_rate' => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'min_redemption_points' => ['required', 'integer', 'min:0'],
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
            'redemption_rate.min' => 'معدل الاستبدال يجب أن يكون أكبر من صفر.',
            'silver_threshold.gte' => 'حد الفئة الفضية يجب ألا يقل عن حد الفئة البرونزية.',
            'gold_threshold.gte' => 'حد الفئة الذهبية يجب ألا يقل عن حد الفئة الفضية.',
        ];
    }
}
