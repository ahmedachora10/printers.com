<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * موضع composer على الخادم. الاستضافة المشتركة تُخفيه في مواضع شتّى، وقد
 * لا تُتيحه أصلاً، فالسؤال عنه يسبق كل خطةٍ تعتمد عليه.
 */
class ComposerBinary
{
    private static ?string $path = null;

    private static bool $lookedUp = false;

    /**
     * @var list<string>
     */
    private const CANDIDATES = [
        '/opt/cpanel/composer/bin/composer',
        '/usr/local/bin/composer',
        '/usr/bin/composer',
    ];

    public static function path(): ?string
    {
        if (self::$lookedUp) {
            return self::$path;
        }

        self::$lookedUp = true;

        return self::$path = Shell::locate('composer', [
            base_path('composer.phar'),
            ...self::CANDIDATES,
        ]);
    }

    public static function available(): bool
    {
        return self::path() !== null;
    }

    /**
     * تثبيت الحزم من ملف القفل. حزم التطوير تُطلب حين يُراد زارعٌ تجريبي،
     * فـ faker منها ولا سبيل إلى جلبها وحدها.
     *
     * composer.phar يُشغَّل بمُفسِّرٍ صريح: `php` في المسار على cPanel قد
     * يكون نسخةً لا تُقلع بها حزم المشروع أصلاً.
     */
    public static function install(bool $withDev = false): void
    {
        $composer = self::path();

        if ($composer === null) {
            throw new RuntimeException('composer غير متاح على هذا الخادم.');
        }

        $home = storage_path('app/composer');
        File::ensureDirectoryExists($home);

        $binary = str_ends_with($composer, '.phar') ? [PhpBinary::path(), $composer] : [$composer];

        $result = Process::path(base_path())
            ->timeout(1200)
            ->env([
                'COMPOSER_HOME' => $home,
                'COMPOSER_MEMORY_LIMIT' => '-1',
                'COMPOSER_NO_INTERACTION' => '1',
            ])
            ->run([
                ...$binary,
                'install',
                ...($withDev ? [] : ['--no-dev']),
                '--prefer-dist',
                '--no-progress',
                '--no-interaction',
                '--optimize-autoloader',
            ]);

        if ($result->failed()) {
            throw new RuntimeException('فشل composer install: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /** للاختبارات: تثبيت جواب البحث — null تعني «لا composer على هذا الخادم». */
    public static function assume(?string $path): void
    {
        self::$lookedUp = true;
        self::$path = $path;
    }

    /** العودة إلى البحث الحقيقي. */
    public static function forget(): void
    {
        self::$lookedUp = false;
        self::$path = null;
    }
}
