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
            // تاسك 126: سجلّ التعديلات — القديم ⇒ الجديد لما تغيّر، من ومتى.
            // ولا `canUpdate`/`canDelete`: الشاشة للإدارة وحدها والقائمة مقصورةٌ
            // على فرع المستخدم، فالسياسة صادقةٌ على كل صفٍّ يصله.
            'history' => $this->relationLoaded('activities') ? $this->readableHistory() : [],
        ];
    }

    /** تسميات الحقول القابلة للتعديل (تاسك 126) — تُقرأ في سجلّ التعديلات. */
    private const FIELD_LABELS = [
        'amount' => 'القيمة',
        'reason' => 'السبب',
        'reason_note' => 'شرح السبب',
        'deducted_at' => 'التاريخ',
        'notes' => 'الملاحظات',
    ];

    /**
     * تعديلات هذا القيد سطراً سطراً: تسمية الحقل وقيمتاه مقروءتين — فالواجهة
     * تعرضها كما هي بلا خريطة تسمياتٍ ثانية عندها.
     *
     * @return list<array<string, mixed>>
     */
    private function readableHistory(): array
    {
        $readable = fn (?string $field, mixed $value) => match (true) {
            $value === null || $value === '' => '—',
            $field === 'reason' => DeductionReasonEnum::tryFrom($value)?->label() ?? $value,
            $field === 'deducted_at' => Carbon::parse($value)->format('d/m/Y'),
            default => (string) $value,
        };

        return $this->activities
            ->where('event', 'updated')
            ->sortByDesc('id')
            ->map(fn ($activity) => [
                'id' => $activity->id,
                'byName' => $activity->causer?->name,
                'at' => $activity->created_at->format('d/m/Y H:i'),
                'changes' => collect($activity->properties['attributes'] ?? [])
                    ->map(fn ($value, $field) => [
                        'label' => self::FIELD_LABELS[$field] ?? $field,
                        'old' => $readable($field, $activity->properties['old'][$field] ?? null),
                        'new' => $readable($field, $value),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
