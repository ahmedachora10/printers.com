<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * شاشة النشر بابٌ إلى الخادم من داخل المنتج، فتبقى معدومةً — لا ممنوعة —
 * ما لم يُرفع DEPLOY_UI_ENABLED. و404 مقصودة: لا تُخبر من يجسّ الأبواب أنّ
 * ثمّة باباً هنا أصلاً.
 */
class EnsureDeployUiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('deploy.ui.enabled'), 404);

        return $next($request);
    }
}
