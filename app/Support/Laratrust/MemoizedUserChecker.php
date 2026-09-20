<?php

namespace App\Support\Laratrust;

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
 *
 * الصلاحيات (`hasPermission`) خارج هذا: موضعان اثنان في النظام كلِّه.
 */
class MemoizedUserChecker extends UserDefaultChecker
{
    /** @var WeakMap<object, array<array-key, mixed>>|null */
    private static ?WeakMap $roles = null;

    protected function userCachedRoles(): array
    {
        self::$roles ??= new WeakMap;

        return self::$roles[$this->user] ??= parent::userCachedRoles();
    }

    public function currentUserFlushCache(): void
    {
        self::$roles?->offsetUnset($this->user);

        parent::currentUserFlushCache();
    }
}
