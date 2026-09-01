<?php

namespace App\Http\Controllers;

use App\Actions\System\StreamDeployAction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تشغيل أمر النشر من الويب — ليَقدر خطُّ GitHub Actions أن يُطلقه بعد بناء
 * الأصول، وليُغني عن سطر الأوامر على استضافةٍ لا تُتيحه دائماً.
 *
 * الباب مغلقٌ ما لم يوجد مفتاحٌ في البيئة، ولا يفتحه إلا مفتاحٌ مطابق. ومخرجات
 * الأمر تُدفَق كما تُكتب، فمن يُطلقه يرى أين وصل بدل أن ينتظر صفحةً بيضاء
 * قد تنقطع قبل أن تعود.
 */
class DeployController extends Controller
{
    public function __invoke(Request $request, StreamDeployAction $deploy): StreamedResponse
    {
        $token = (string) config('deploy.token');

        abort_unless(config('deploy.enabled') && $token !== '', 404);

        $provided = (string) ($request->header('X-Deploy-Token') ?? $request->query('token', ''));

        if (! hash_equals($token, $provided)) {
            activity('deploy')
                ->withProperties(['ip' => $request->ip(), 'agent' => $request->userAgent()])
                ->log('محاولة نشر بمفتاحٍ خاطئ');

            abort(403);
        }

        return $deploy->handle($this->options($request), [
            'ip' => $request->ip(),
            'via' => 'token',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(Request $request): array
    {
        $options = ['--force' => true];

        if ($request->boolean('dry')) {
            $options['--dry-run'] = true;
        }

        $branch = trim((string) $request->input('branch', ''));

        if ($branch !== '') {
            abort_unless((bool) preg_match('/^[\w.\/-]+$/', $branch), 422, 'اسم فرعٍ غير صالح.');

            $options['--branch'] = $branch;
        }

        $assets = trim((string) $request->input('assets', ''));

        if ($assets !== '') {
            $options['--assets'] = $this->assets($assets);
        }

        return $options;
    }

    /**
     * رابطٌ للأصول أو ملفٌّ داخل مجلّد التطبيق — لا مسارات مطلقة من الخارج.
     */
    private function assets(string $assets): string
    {
        if (Str::startsWith($assets, ['http://', 'https://'])) {
            return $assets;
        }

        $path = realpath(base_path($assets));

        abort_if($path === false || ! Str::startsWith($path, realpath(base_path()).DIRECTORY_SEPARATOR), 422, 'مسار أصولٍ غير صالح.');

        return $path;
    }
}
