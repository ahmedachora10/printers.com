<?php

namespace App\Http\Requests\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

/**
 * تاسك 238 — طلبات إنشاء/تعديل سجلٍّ ScopedToBranches.
 *
 * السوبر أدمن يرسل branch_ids[] (فارغ = عامة)؛ طلبٌ بلا branch_ids لا يغيّر نطاق
 * سجلٍّ قائم. غيره يُثبَّت على [فرعه] مهما أرسل.
 */
trait ValidatesBranchScope
{
    /** السجلّ المعدَّل، أو null عند الإنشاء. */
    abstract protected function scopedRecord(): ?Model;

    protected function prepareBranchScope(): void
    {
        $user = $this->user();
        $record = $this->scopedRecord();

        $branchIds = match (true) {
            ! $user?->roleName->isSuperAdmin() => [$user?->branchId],
            $this->has('branch_ids') => (array) $this->input('branch_ids'),
            default => $record?->branchIds() ?? [],
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
            $model = $record ? $record::class : $this->scopedModel();

            $taken = $model::query()
                ->where('name', $value)
                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                ->overlappingBranches($this->input('branch_ids', []))
                ->exists();

            if ($taken) {
                $fail($message);
            }
        };
    }

    /** @return class-string<Model> */
    abstract protected function scopedModel(): string;

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
}
