<?php

use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config()->set('deploy.token', 'secret-token');
    config()->set('deploy.enabled', true);
});

it('يُخفي المسار ما لم يُضبط مفتاح النشر', function () {
    config()->set('deploy.token', null);

    $this->get('/deploy?token=secret-token')->assertNotFound();
});

it('يُخفي المسار حين يُوقفه الإعداد', function () {
    config()->set('deploy.enabled', false);

    $this->get('/deploy?token=secret-token')->assertNotFound();
});

it('يمنع المفتاح الخاطئ ويسجّل المحاولة', function () {
    $this->get('/deploy?token=wrong')->assertForbidden();

    $this->assertDatabaseHas('activity_log', ['log_name' => 'deploy']);
});

it('يمنع الطلب ما دام هناك نشرٌ قيد التنفيذ', function () {
    Cache::lock('deploy:running', 60)->get();

    $this->get('/deploy?token=secret-token')->assertStatus(409);
});

it('يرفض مسار أصولٍ خارج مجلّد التطبيق', function () {
    $this->get('/deploy?token=secret-token&assets=../../etc/passwd')->assertStatus(422);
});

it('يرفض اسم فرعٍ غير صالح', function () {
    $this->get('/deploy?token=secret-token&branch=main;rm -rf')->assertStatus(422);
});

it('يقبل المفتاح الصحيح ويُدفق مخرجات الأمر', function () {
    $response = $this->get('/deploy?token=secret-token&dry=1');

    $response->assertOk();
    $response->assertHeader('content-type', 'text/plain; charset=utf-8');

    expect($response->streamedContent())
        ->toContain('نشر')
        ->toContain('لن يُنفَّذ شيء');
});

it('يقبل المفتاح في الترويسة ويتخطّى فحص CSRF على POST', function () {
    $this->post('/deploy?dry=1', [], ['X-Deploy-Token' => 'secret-token'])->assertOk();
});
