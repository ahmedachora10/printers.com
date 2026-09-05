<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * من يُؤذن له في شاشة النشر: السوبر أدمن بحكم دوره، أو من فتحها بالمفتاح.
 *
 * المفتاح هو DEPLOY_TOKEN نفسه الذي يفتح مسار ‎/deploy‎، فلا سلطةَ جديدة هنا
 * — من يملكه يملك النشر أصلاً — وإنما بابٌ أيسر إليه لمن ليس مديراً عاماً،
 * كالمطوّر الذي يُصلح على عجل. والفتح يُخزَّن في الجلسة لا في رابط، فلا يبقى
 * المفتاح في تاريخ المتصفّح ولا في سجلّات الوسطاء.
 *
 * وراية DEPLOY_UI_ENABLED فوق هذا كله: إن كانت مطفأة فالمسار معدومٌ للجميع،
 * حاملَ المفتاح ومديرَ النظام سواء.
 */
class DeployAccess
{
    public const SESSION_KEY = 'deploy_unlocked';

    /** ختمُ آخر استعمالٍ للفتح، به تُقاس مدّة السكون. */
    public const SESSION_TOUCHED_KEY = 'deploy_unlocked_at';

    public static function granted(Request $request): bool
    {
        return self::isSuperAdmin($request) || self::unlockedBySession($request);
    }

    public static function isSuperAdmin(Request $request): bool
    {
        return (bool) $request->user()?->hasRole('super-admin');
    }

    /**
     * الفتح بالمفتاح لا يدوم دوامَ الجلسة: من فتح على متصفّحٍ مشترَك ثم انصرف
     * كان يترك باب الخادم مفتوحاً خلفه ما دامت الجلسة حيّة. فيُقاس السكون،
     * وكلُّ طلبٍ مأذونٍ يُجدّد الختم، فلا يُقطع على أحدٍ عملٌ جارٍ ولا تبقى
     * جلسةٌ متروكة مفتوحة.
     *
     * والسوبر أدمن خارج هذا كلّه: إذنه دوره لا مفتاحُه.
     */
    public static function unlockedBySession(Request $request): bool
    {
        if ($request->session()->get(self::SESSION_KEY) !== true) {
            return false;
        }

        if (self::idleTooLong($request)) {
            self::lock($request);

            return false;
        }

        $request->session()->put(self::SESSION_TOUCHED_KEY, now()->getTimestamp());

        return true;
    }

    /**
     * لا مفتاح مضبوط = لا فتح بالمفتاح. بدون هذا الشرط يُطابق hash_equals
     * فراغاً بفراغ، فينفتح الباب لمن لم يُعطَ شيئاً.
     */
    public static function configured(): bool
    {
        return (string) config('deploy.token') !== '';
    }

    /**
     * أيُكشف للطارق أنّ الخادم بلا مفتاحٍ أصلاً؟ الكشف عونٌ للمدير يُشخّص به
     * إعداده، وهو لمن يجسّ الأبواب خبرٌ نافع. فيُقصر على من دخل بحساب، ويُعرض
     * على الزائر المجهول نموذجُ المفتاح دائماً كأنّ ثمّة مفتاحاً.
     */
    public static function revealsConfiguration(Request $request): bool
    {
        return $request->user() !== null;
    }

    public static function matches(string $provided): bool
    {
        return self::configured() && hash_equals((string) config('deploy.token'), $provided);
    }

    public static function unlock(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, true);
        $request->session()->put(self::SESSION_TOUCHED_KEY, now()->getTimestamp());

        // ترقيةُ إذنٍ داخل جلسةٍ قائمة تستحقّ معرّفاً جديداً.
        $request->session()->regenerate();
    }

    public static function lock(Request $request): void
    {
        $request->session()->forget([self::SESSION_KEY, self::SESSION_TOUCHED_KEY]);
    }

    /**
     * فتحٌ بلا ختمٍ زمني — جلسةٌ سبقت هذا الشرط — يُعدّ منتهياً: الأمان في
     * إعادة السؤال لا في افتراض الحداثة.
     */
    private static function idleTooLong(Request $request): bool
    {
        $minutes = (int) config('deploy.ui.unlock_ttl');

        if ($minutes <= 0) {
            return false;
        }

        $touched = $request->session()->get(self::SESSION_TOUCHED_KEY);

        if (! is_int($touched)) {
            return true;
        }

        return (now()->getTimestamp() - $touched) > $minutes * 60;
    }
}
