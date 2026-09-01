<?php

it('يذكر الخطوات المستحدثة في العرض المجرّد', function () {
    $this->artisan('app:deploy', ['--dry-run' => true])
        ->expectsOutputToContain('الزارعات: الأدوار والصلاحيات')
        ->expectsOutputToContain('فحص الموقع بعد الفتح')
        ->expectsOutputToContain('التراجع إن سقطت خطوة')
        ->run();
});

it('لا يُنفّذ شيئاً في العرض المجرّد', function () {
    $this->artisan('app:deploy', ['--dry-run' => true])
        ->expectsOutputToContain('لن يُنفَّذ شيء')
        ->run();

    expect(is_dir(public_path('build.previous')))->toBeFalse();
});
