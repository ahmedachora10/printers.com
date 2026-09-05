<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * تشغيل زارعٍ في مُفسِّرٍ جديد.
 *
 * يُحتاج إليه حين تُثبَّت faker في أثناء العملية نفسها: دالّة fake() تُعرَّف
 * عند الإقلاع وبشرط وجود ‎\Faker\Factory‎، وذاك الشرط قُضي فيه قبل التثبيت،
 * ومُحمِّل الأصناف المُسجَّل يحمل خريطةً قديمة لا يُحدّثها طلبُ autoload.php
 * مرةً أخرى. فلا علاج إلا عمليةٌ تقرأ vendor كما صار.
 *
 * يشترك فيه أمرُ النشر وشاشةُ الأوامر المفردة، فالمنطق واحدٌ في الموضعين.
 */
class SeederRunner
{
    public static function runFresh(string $class): void
    {
        $result = Process::path(base_path())
            ->timeout(1800)
            ->run([PhpBinary::path(), base_path('artisan'), 'db:seed', '--class='.$class, '--force']);

        if ($result->failed()) {
            throw new RuntimeException(
                'فشل الزارع '.class_basename($class).': '.trim($result->errorOutput() ?: $result->output())
            );
        }
    }

    /**
     * هل يمكن فتح عمليةٍ جديدة أصلاً؟ بعض الاستضافات تُعطّل proc_open.
     */
    public static function canRunFresh(): bool
    {
        return Shell::available();
    }
}
