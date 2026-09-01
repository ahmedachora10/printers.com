<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * مساعدٌ صغيرٌ للتعامل مع الأوامر الخارجية على الاستضافات المشتركة، حيث
 * قد يكون proc_open معطّلاً أصلاً، وحيث لا يقع git ولا composer ولا mysqldump
 * في مواضعها المعتادة. كل ما هنا يُرجع null بدل أن يرمي، فالنشر يمضي
 * ويُبلّغ عمّا تعذّر عليه بدل أن يتوقف.
 */
class Shell
{
    /**
     * هل يسمح إعداد PHP بتشغيل عمليات خارجية أصلاً؟
     */
    public static function available(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map(
            fn (string $function): string => trim($function),
            explode(',', (string) ini_get('disable_functions'))
        );

        return ! in_array('proc_open', $disabled, true);
    }

    /**
     * موضع أداةٍ على الخادم: نجرّب المسارات المعروفة أولاً ثم نسأل الصدفة.
     *
     * @param  list<string>  $fallbacks
     */
    public static function locate(string $binary, array $fallbacks = []): ?string
    {
        foreach ($fallbacks as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        if (! self::available()) {
            return null;
        }

        try {
            $result = Process::timeout(15)->run('command -v '.escapeshellarg($binary));
        } catch (Throwable) {
            return null;
        }

        if ($result->failed()) {
            return null;
        }

        $path = trim(strtok($result->output(), "\n") ?: '');

        return $path !== '' ? $path : null;
    }
}
