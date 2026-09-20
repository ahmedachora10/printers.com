<?php

namespace App\Support\Laratrust;

use Closure;
use Laratrust\Checkers\User\UserDefaultChecker;
use WeakMap;

/**
 * Laratrust يقرأ أدوار المستخدم من الكاش **مرّةً لكل اسمٍ** في الفحص الواحد:
 * `role:branch-admin|super-admin|accountant|employee` على مسارٍ واحد أربعُ
 * قراءات، وكلُّ قراءةٍ استعلامٌ كامل لأن مخزن الكاش هنا قاعدة البيانات.
 *
 * فتُحفظ النتيجة على **نسخة المستخدم نفسها** في `WeakMap`: الطلب التالي يُنشئ
 * نسخةً جديدة فيقرأ من جديد، والنسخة القديمة تُجمع مع قيمتها بلا تسريب. وتغييرُ
 * الأدوار يمرّ بـ`flushCache()` فيُمحى المحفوظ معه — فلا يبقى دورٌ قديمٌ بعد
 * تعديله.
 */
class MemoizedUserChecker extends UserDefaultChecker
{
    /** @var WeakMap<object, array<string, array<array-key, mixed>>>|null */
    private static ?WeakMap $memo = null;

    protected function userCachedRoles(): array
    {
        return $this->memo('roles', fn () => parent::userCachedRoles());
    }

    public function userCachedPermissions(): array
    {
        return $this->memo('permissions', fn () => parent::userCachedPermissions());
    }

    public function currentUserFlushCache(): void
    {
        self::$memo?->offsetUnset($this->user);

        parent::currentUserFlushCache();
    }

    /**
     * @param  Closure(): array<array-key, mixed>  $resolve
     * @return array<array-key, mixed>
     */
    private function memo(string $bucket, Closure $resolve): array
    {
        self::$memo ??= new WeakMap;

        $buckets = self::$memo[$this->user] ?? [];

        if (! array_key_exists($bucket, $buckets)) {
            $buckets[$bucket] = $resolve();
            self::$memo[$this->user] = $buckets;
        }

        return $buckets[$bucket];
    }
}
