<?php

namespace App\Http\Requests\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

/**
 * تاسك 238 — طلب إنشاء/تعديل سجلٍّ ScopedToBranches. الصنف يعرّف
 * `protected string $scopedModel`، والسجلّ المعدَّل يُقرأ من معاملات المسار.
 *
 * السوبر أدمن يرسل branch_ids[] (فارغ = عامة)؛ طلبٌ بلا branch_ids لا يغيّر نطاق
 * سجلٍّ قائم. غيره يُثبَّت على [فرعه] مهما أرسل. والحصر يُرفض إن استُعمل السجلّ في
 * فرعٍ خارج النطاق الجديد (لا شيء يُفحص عند الإنشاء).
 */
trait ValidatesBranchScope
{
    protected function prepareForValidation(): void
    {
        $user = $this->user();

        $branchIds = match (true) {
            ! $user?->roleName->isSuperAdmin() => [$user?->branchId],
            $this->has('branch_ids') => (array) $this->input('branch_ids'),
            default => $this->scopedRecord()?->branchIds() ?? [],
        };

        $this->merge(['branch_ids' => array_values(array_unique(array_map('intval', array_filter($branchIds))))]);
    }

    /** @return array<string, mixed> */
    protected function branchScopeRules(): array
    {
        return [
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ];
    }

    /**
     * الاسم فريد بين كل ما يراه أيّ فرعٍ من النطاق: لا سجلّ آخر بالاسم نفسه عامٌّ أو
     * يشارك فرعاً منه (والسجلّ العامّ لا يشارك اسمه أحداً).
     */
    protected function uniqueNameInScope(string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message) {
            $record = $this->scopedRecord();

            $taken = $this->scopedModel::query()
                ->where('name', $value)
                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                ->visibleToBranch($this->input('branch_ids', []))
                ->exists();

            if ($taken) {
                $fail($message);
            }
        };
    }

    /**
     * حصر سجلٍّ مستعمَلٍ في فروعٍ خارج النطاق الجديد يتركها على سجلٍّ لا تراه.
     *
     * @return array<int, callable>
     */
    protected function narrowingCheck(string $usedBy): array
    {
        return [function (Validator $validator) use ($usedBy) {
            $outside = $this->scopedRecord()?->usedOutside($this->input('branch_ids', [])) ?? [];

            if ($outside !== []) {
                $validator->errors()->add('branch_ids', "لها {$usedBy} في: ".implode('، ', $outside).' — أضِفها إلى النطاق أو اتركها عامة.');
            }
        }];
    }

    /** السجلّ المعدَّل من معاملات المسار، أو null عند الإنشاء. */
    private function scopedRecord(): ?Model
    {
        return collect($this->route()?->parameters() ?? [])->first(fn ($p) => $p instanceof $this->scopedModel);
    }
}
