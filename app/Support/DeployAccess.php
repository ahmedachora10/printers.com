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

    public static function granted(Request $request): bool
    {
        return self::isSuperAdmin($request) || $request->session()->get(self::SESSION_KEY) === true;
    }

    public static function isSuperAdmin(Request $request): bool
    {
        return (bool) $request->user()?->hasRole('super-admin');
    }

    public static function unlockedBySession(Request $request): bool
    {
        return $request->session()->get(self::SESSION_KEY) === true;
    }

    /**
     * لا مفتاح مضبوط = لا فتح بالمفتاح. بدون هذا الشرط يُطابق hash_equals
     * فراغاً بفراغ، فينفتح الباب لمن لم يُعطَ شيئاً.
     */
    public static function configured(): bool
    {
        return (string) config('deploy.token') !== '';
    }

    public static function matches(string $provided): bool
    {
        return self::configured() && hash_equals((string) config('deploy.token'), $provided);
    }

    public static function unlock(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, true);

        // ترقيةُ إذنٍ داخل جلسةٍ قائمة تستحقّ معرّفاً جديداً.
        $request->session()->regenerate();
    }

    public static function lock(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }
}
