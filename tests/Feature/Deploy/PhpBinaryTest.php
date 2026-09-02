<?php

use App\Support\PhpBinary;

beforeEach(function () {
    config()->set('deploy.php_binary', null);
    PhpBinary::forget();
});

afterEach(function () {
    PhpBinary::forget();
});

it('يرجع إلى المُفسِّر الحالي حين لا يُضبط شيء ولا يوجد ea-php83', function () {
    $php = PhpBinary::describe();

    // على هذا الجهاز لا وجود لمسارات cPanel، فالمنتظر هو المُفسِّر العامل.
    expect($php['path'])->toBe(PHP_BINARY)
        ->and($php['versionId'])->toBe(PHP_VERSION_ID);
});

it('يُقدّم ما ضُبط في البيئة على ما سواه', function () {
    config()->set('deploy.php_binary', PHP_BINARY);
    PhpBinary::forget();

    $php = PhpBinary::describe();

    expect($php['path'])->toBe(PHP_BINARY)
        ->and($php['source'])->toBe('DEPLOY_PHP_BINARY')
        ->and($php['version'])->toBe(PHP_VERSION);
});

it('يُبلّغ عن مسارٍ مضبوطٍ لا وجود له', function () {
    config()->set('deploy.php_binary', '/opt/cpanel/ea-php83/root/usr/bin/php-does-not-exist');
    PhpBinary::forget();

    expect(PhpBinary::misconfigured())->toContain('غير موجود');
});

it('لا يشكو حين لا يُضبط شيء', function () {
    expect(PhpBinary::misconfigured())->toBeNull();
});

it('يبني سطر تشغيلٍ مطلق الطرفين', function () {
    $command = PhpBinary::artisanCommand('app:deploy --dry-run');

    expect($command)->toStartWith(PHP_BINARY)
        ->and($command)->toContain(base_path('artisan'))
        ->and($command)->toEndWith('app:deploy --dry-run');
});

it('يذكر المُفسِّر الصحيح في التحقّق المسبق حين تكون النسخة أقدم من المطلوب', function () {
    // لا سبيل إلى تشغيل الاختبار على 8.2، فنكتفي بأن الحدّ هو المعلن عنه
    // وأن الرسالة تُبنى من المسار المطلق لا من كلمة php المجرّدة.
    expect(PhpBinary::MINIMUM)->toBe(80300)
        ->and(PhpBinary::artisanCommand())->not->toStartWith('php ');
});
