<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('deploy.remote.url', 'https://example.test');
    config()->set('deploy.remote.token', 'server-token');
});

it('يمتنع ما لم يُضبط عنوان الخادم', function () {
    config()->set('deploy.remote.url', null);

    $this->artisan('app:deploy-remote', ['--force' => true])
        ->expectsOutputToContain('لا عنوان للخادم')
        ->assertFailed();
});

it('يمتنع ما لم يُضبط مفتاح النشر', function () {
    config()->set('deploy.remote.token', null);

    $this->artisan('app:deploy-remote', ['--force' => true])
        ->expectsOutputToContain('لا مفتاح للنشر')
        ->assertFailed();
});

it('يُرسل المفتاح في الترويسة ويطبع مخرجات الخادم', function () {
    Http::fake([
        'example.test/deploy*' => Http::response("سحب الكود ... تم\n\n== اكتمل النشر ==\n", 200),
    ]);

    $this->artisan('app:deploy-remote', ['--force' => true])
        ->expectsOutputToContain('سحب الكود')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Deploy-Token', 'server-token')
        && $request->method() === 'POST'
        && str_starts_with($request->url(), 'https://example.test/deploy'));
});

it('يردّ بالفشل حين يكتب الخادم علامة الفشل', function () {
    Http::fake([
        'example.test/deploy*' => Http::response("هجرات قاعدة البيانات ... فشل\n\n== فشل النشر ==\n", 200),
    ]);

    $this->artisan('app:deploy-remote', ['--force' => true])
        ->expectsOutputToContain('فشل النشر على الخادم')
        ->assertFailed();
});

it('يردّ بالفشل حين تنقطع المخرجات دون علامةٍ ختامية', function () {
    Http::fake(['example.test/deploy*' => Http::response("نصفُ نشرٍ ثم صمت\n", 200)]);

    $this->artisan('app:deploy-remote', ['--force' => true])
        ->expectsOutputToContain('دون علامةٍ ختامية')
        ->assertFailed();
});

it('يُمرّر الفرع والعرض المجرّد في الاستعلام', function () {
    Http::fake(['example.test/deploy*' => Http::response("== اكتمل النشر ==\n", 200)]);

    $this->artisan('app:deploy-remote', ['--branch' => 'main', '--dry-run' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'dry=1')
        && str_contains($request->url(), 'branch=main'));
});

it('يشرح رموز الردّ التي يعرفها', function (int $status, string $expected) {
    Http::fake(['example.test/deploy*' => Http::response('', $status)]);

    $this->artisan('app:deploy-remote', ['--force' => true])
        ->expectsOutputToContain($expected)
        ->assertFailed();
})->with([
    [403, 'مفتاح النشر مرفوض'],
    [404, 'المسار مغلق'],
    [409, 'هناك نشرٌ قيد التنفيذ'],
    [429, 'تجاوزت حدّ المحاولات'],
]);
