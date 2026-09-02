<?php

namespace App\Support;

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
