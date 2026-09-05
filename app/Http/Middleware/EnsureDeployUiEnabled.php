<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * شاشة النشر بابٌ إلى الخادم من داخل المنتج، فتبقى معدومةً — لا ممنوعة —
 * ما لم يُرفع DEPLOY_UI_ENABLED. و404 مقصودة: لا تُخبر من يجسّ الأبواب أنّ
 * ثمّة باباً هنا أصلاً.
 *
 * ولا معنى لإخفاءٍ تنقضه فهرسةُ محرّك بحث: صفحةٌ مفتوحةٌ بلا حساب تُزحف
 * وتُفهرس، فيصير البابُ المستور نتيجةَ بحثٍ عن اسم الموقع. فتُختم كلّ ردود
 * هذه المسارات بـ X-Robots-Tag.
 */
class EnsureDeployUiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('deploy.ui.enabled'), 404);

        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
