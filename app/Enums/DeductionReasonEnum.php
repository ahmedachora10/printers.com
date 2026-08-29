<?php

namespace App\Enums;

/**
 * تاسك 74: أسباب الحسم كما عدّدها العميل حرفياً — قائمة مغلقة كي يبقى الحسم
 * قابلاً للتصنيف والقياس، و«أخرى» تُلزم بشرحٍ نصّي بدل أن تصير مهرباً صامتاً.
 */
enum DeductionReasonEnum: string
{
    case Performance = 'performance';
    case ExecutionError = 'execution_error';
    case NonCompliance = 'non_compliance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Performance => 'قصور في الأداء',
            self::ExecutionError => 'أخطاء في تنفيذ العمل',
            self::NonCompliance => 'عدم الالتزام بالمهام والتعليمات',
            self::Other => 'حالات أخرى',
        };
    }

    /** «أخرى» وحدها تحتاج شرحاً — البقية مفهومة بذاتها. */
    public function requiresNote(): bool
    {
        return $this === self::Other;
    }
}
