<?php

namespace App\Http\Resources\Deduction;

use App\Enums\DeductionReasonEnum;
use App\Models\EmployeeDeduction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeDeduction
 */
class EmployeeDeductionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'userName' => $this->user?->name,
            'branchId' => $this->branch_id,
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'amount' => (float) $this->amount,
            'reason' => $this->reason->value,
            'reasonLabel' => $this->reason->label(),
            'reasonNote' => $this->reason_note,
            'reasonText' => $this->reasonLabel(),
            'deductedBy' => $this->deductedBy?->name,
            'deductedAt' => $this->deducted_at?->format('d/m/Y'),
            // تاسك 126: الخام لخانة التاريخ في نافذة التعديل — المعروض أعلاه
            // بصيغة العرض، و`<input type="date">` لا يقرؤها.
            'deductedAtDate' => $this->deducted_at?->format('Y-m-d'),
            'notes' => $this->notes,
            'canUpdate' => $request->user()->can('update', $this->resource),
            'canDelete' => $request->user()->can('delete', $this->resource),
            // تاسك 126: سجلّ التعديلات — القديم ⇒ الجديد لما تغيّر، من ومتى.
            // نفس عارض تاسك 113 في نافذة المصروف، لا عارضٌ ثانٍ.
            'history' => $this->relationLoaded('activities') ? $this->readableHistory() : [],
        ];
    }

    /**
     * تعديلات هذا القيد بقيمٍ مقروءة: تسمية السبب بدل قيمته، والتاريخ بصيغته
     * المعروضة.
     *
     * @return list<array<string, mixed>>
     */
    private function readableHistory(): array
    {
        $readable = fn (array $fields) => collect($fields)->map(fn ($value, $field) => match (true) {
            $value === null => null,
            $field === 'reason' => DeductionReasonEnum::tryFrom($value)?->label() ?? $value,
            $field === 'deducted_at' => Carbon::parse($value)->format('d/m/Y'),
            default => $value,
        })->all();

        return $this->activities
            ->where('event', 'updated')
            ->sortByDesc('id')
            ->map(fn ($activity) => [
                'id' => $activity->id,
                'byName' => $activity->causer?->name,
                'at' => $activity->created_at->format('d/m/Y H:i'),
                'old' => $readable($activity->properties['old'] ?? []),
                'new' => $readable($activity->properties['attributes'] ?? []),
            ])
            ->values()
            ->all();
    }
}
