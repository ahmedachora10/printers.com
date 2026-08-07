<?php

namespace App\Http\Requests\Agent\Concerns;

use App\Enums\AgentDiscountModeEnum;
use App\Enums\AgentDiscountTypeEnum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * An agent (مندوب) works with one or more branches, each on its own rate and
 * discount mode. The form posts those links as `branches[]`.
 *
 * The flat `discount_mode` / `discount_type` / `rate` fields stay: they are the
 * agent's profile defaults, and when no `branches[]` is posted they also define
 * the single link for `branch_id` — which keeps every single-branch caller
 * working unchanged.
 */
trait NormalizesAgentBranchTerms
{
    /**
     * Build the effective `branches[]` payload, and confine branch-scoped actors
     * to their own branch so they can neither add nor rewrite another branch's
     * terms.
     */
    protected function normalizeBranchTerms(): void
    {
        $user = Auth::user();
        $branches = $this->input('branches');

        if (! is_array($branches) || $branches === []) {
            // No explicit links: fall back to one link on the flat fields.
            $branches = [[
                'branch_id' => $this->input('branch_id'),
                'discount_mode' => $this->input('discount_mode'),
                'discount_type' => $this->input('discount_type'),
                'rate' => $this->input('rate'),
            ]];
        }

        if (! $user->roleName?->isSuperAdmin()) {
            $ownBranch = $user->branchId;

            // Whatever was posted, a branch-scoped actor only ever writes the row
            // for their own branch. Their edits must leave other branches' links
            // untouched — the action detaches nothing on their behalf.
            $own = collect($branches)->firstWhere('branch_id', $ownBranch)
                ?? collect($branches)->first();

            $branches = [[
                'branch_id' => $ownBranch,
                'discount_mode' => $own['discount_mode'] ?? $this->input('discount_mode'),
                'discount_type' => $own['discount_type'] ?? $this->input('discount_type'),
                'rate' => $own['rate'] ?? $this->input('rate'),
            ]];
        }

        $branches = array_values(array_map(fn (array $row) => [
            'branch_id' => $row['branch_id'] ?? null,
            'discount_mode' => $row['discount_mode'] ?? null,
            // Default the type so the rate cap treats the rate as a
            // percentage unless a fixed amount is explicitly chosen.
            'discount_type' => $row['discount_type'] ?? 'percentage',
            // Left null when absent so `required` still reports a missing rate
            // rather than silently reading as zero.
            'rate' => $row['rate'] ?? null,
        ], $branches));

        $this->merge([
            'branches' => $branches,
            // The profile defaults mirror the primary link, so a caller that
            // sent only `branches[]` need not repeat the flat fields.
            'discount_mode' => $branches[0]['discount_mode'] ?? null,
            'discount_type' => $branches[0]['discount_type'] ?? 'percentage',
            'rate' => $branches[0]['rate'] ?? null,
        ]);
    }

    /** @return array<string, mixed> */
    protected function branchTermRules(): array
    {
        return [
            'branches' => ['required', 'array', 'min:1'],
            'branches.*.branch_id' => ['required', 'integer', 'distinct', 'exists:branches,id'],
            'branches.*.discount_mode' => ['required', new Enum(AgentDiscountModeEnum::class)],
            'branches.*.discount_type' => ['required', new Enum(AgentDiscountTypeEnum::class)],
            'branches.*.rate' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Percentage rates are capped at 100; a fixed SAR amount is not. The cap is
     * per row because each branch chooses its own type.
     */
    protected function validateBranchRateCaps(Validator $validator): void
    {
        foreach ((array) $this->input('branches', []) as $index => $row) {
            if (($row['discount_type'] ?? 'percentage') !== 'fixed' && (float) ($row['rate'] ?? 0) > 100) {
                $validator->errors()->add(
                    "branches.{$index}.rate",
                    'النسبة المئوية يجب ألا تتجاوز 100%.',
                );
            }
        }
    }

    /** @return array<string, string> */
    protected function branchTermMessages(): array
    {
        return [
            'branches.required' => 'يجب ربط المندوب بفرع واحد على الأقل.',
            'branches.*.branch_id.required' => 'يجب تحديد الفرع.',
            'branches.*.branch_id.distinct' => 'لا يمكن ربط المندوب بنفس الفرع مرتين.',
            'branches.*.discount_mode.required' => 'يجب تحديد نمط الخصم لكل فرع.',
            'branches.*.rate.required' => 'يجب تحديد النسبة لكل فرع.',
        ];
    }
}
