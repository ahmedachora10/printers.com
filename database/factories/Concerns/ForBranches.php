<?php

namespace Database\Factories\Concerns;

use Illuminate\Database\Eloquent\Model;

/** تاسك 238 — مصنع نموذجٍ ScopedToBranches: نطاقه هذه الفروع؛ بلا استدعاء = عام. */
trait ForBranches
{
    public function forBranches(int ...$branchIds): static
    {
        return $this->afterCreating(fn (Model $model) => $model->branches()->sync($branchIds));
    }
}
