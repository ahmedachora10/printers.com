<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * تاسك 238 — المعرّف لسجلٍّ (ScopedToBranches) يراه هذا الفرع: عامّ أو يشمله.
 * `$branchId === null` لا يقيّد.
 */
class VisibleToBranch implements ValidationRule
{
    /** @param class-string $model */
    public function __construct(private string $model, private ?int $branchId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = is_numeric($value) && $this->model::query()
            ->whereKey((int) $value)
            ->visibleToBranch($this->branchId)
            ->exists();

        if (! $exists) {
            $fail('validation.exists')->translate();
        }
    }
}
