<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * تاسك 228: فرعٌ انتهى اشتراكه ومهلته يصير «قراءة فقط» لكل مستخدميه — يرون
 * ويطبعون ويصدّرون، ولا يكتبون شيئاً. موضعٌ واحد لكل طلبات الكتابة بدل شرطٍ في
 * كل متحكّم.
 *
 * يمرّ دائماً: القراءة، وتسجيل الخروج، والإشعارات، والملف الشخصي وكلمة المرور،
 * والسوبر أدمن منتحلاً (ليصلح ما يلزم)، والمندوب (بوابته للقراءة ويخدم أكثر من فرع).
 * الرفض خطأ تحقّقٍ لا صفحة 403: النموذج يبقى بما فيه، واللافتة أعلى الصفحة تشرح السبب.
 */
class EnsureBranchSubscriptionActive
{
    private const ALWAYS_ALLOWED = ['logout', 'impersonate.leave', 'notifications.*', 'profile.*', 'password.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user === null
            || $request->isMethodSafe()
            || $request->routeIs(...self::ALWAYS_ALLOWED)
            || $request->session()->has('impersonator_id')
            || $user->roleName?->isAgent()
            || $user->workBranch()?->subscriptionState() !== 'expired'
        ) {
            return $next($request);
        }

        throw ValidationException::withMessages([
            'subscription' => 'انتهى اشتراك الفرع — النظام في وضع القراءة فقط، راجع الإدارة',
        ]);
    }
}
