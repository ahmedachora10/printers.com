<?php

namespace App\Http\Requests\CardType;

use App\Http\Requests\Concerns\ValidatesBranchScope;
use App\Models\CardType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * تاسك 146/243 — إضافة/تعديل نوع بطاقة. مدير الفرع لفرعه وحده؛ السوبر أدمن يختار فرعاً أو
 * عدّة فروع أو لا شيء (عام). الحصر يُرفض إن سجّلت مطابقةُ فرعٍ خارج النطاق مبالغ للنوع.
 */
class SaveCardTypeRequest extends FormRequest
{
    use ValidatesBranchScope;

    protected function prepareForValidation(): void
    {
        $this->prepareBranchScope();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->branchScopeRules(),
            'name' => ['required', 'string', 'max:100', $this->uniqueNameInScope('يوجد نوع بطاقة بهذا الاسم.')],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return $this->narrowingCheck('مطابقات');
    }

    protected function scopedRecord(): ?Model
    {
        return $this->route('cardType');
    }

    protected function scopedModel(): string
    {
        return CardType::class;
    }
}
