<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * ما ستسحبه فاتورةُ خدماتٍ من المخزون، مجموعاً **حسب المنتج** لا حسب السطر:
 * منتجٌ تستهلكه خدمتان في الفاتورة نفسها يُجمع احتياجه أولاً، وإلا مرّ العجز دون
 * أن يُكشف لأن كل سطرٍ وحده يبدو مكفياً.
 *
 * قراءةٌ محضة — لا تكتب حركةً ولا تمنع اعتماداً بنفسها؛ الاعتماد يعرضها ويطلب
 * تأكيداً، ونقطةُ البيع تعرضها إرشاداً.
 *
 * @phpstan-type RequirementRow array{productId: int, name: string, unitName: ?string, required: float, available: float, shortage: float}
 */
class MaterialsRequirement
{
    /** @param Collection<int, array<string, mixed>> $rows */
    public function __construct(public readonly Collection $rows) {}

    public static function empty(): self
    {
        return new self(collect());
    }

    public function hasShortage(): bool
    {
        return $this->rows->contains(fn (array $row) => $row['shortage'] > 0);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function shortages(): Collection
    {
        return $this->rows->filter(fn (array $row) => $row['shortage'] > 0)->values();
    }

    /**
     * رسالة العجز كما تُقرأ في الحوار: اسم الخامة والمطلوب والمتاح لكل ناقص.
     */
    public function message(): string
    {
        $parts = $this->shortages()
            ->map(fn (array $row) => sprintf(
                '%s (مطلوب %s، المتاح %s)',
                $row['name'],
                Quantity::format($row['required']),
                Quantity::format($row['available']),
            ))
            ->all();

        return 'المخزون لا يكفي خامات هذه الفاتورة: '.implode('، ', $parts).'.';
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return $this->rows->values()->all();
    }
}
