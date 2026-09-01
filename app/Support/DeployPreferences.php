<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * آخر ما اختاره المُشغِّل في شاشة النشر، ليجده مُعلَّماً في المرة التالية.
 *
 * تفضيلٌ لا إعداد: يُقرأ ليملأ الشاشة ثم يُعرض على العين قبل أن يُنفَّذ شيء،
 * فلو فسد الملف أو اختلف شكله رجعنا إلى الافتراض بلا ضجيج. ولا يُحفظ منه
 * ما يخصّ البيانات التجريبية، كي لا يمرّ زارعٌ خطيرٌ لأنه مرّ مرة.
 */
class DeployPreferences
{
    private const KEYS = ['pull', 'composer', 'backup', 'migrate', 'seed', 'maintenance', 'health', 'rollback'];

    /**
     * @return array{options: array<string, bool>, seeders: list<string>, branch: string|null}
     */
    public static function load(): array
    {
        $defaults = [
            'options' => array_fill_keys(self::KEYS, true),
            'seeders' => DeploySeeders::DEFAULT,
            'branch' => null,
        ];

        try {
            if (! is_file($path = self::path())) {
                return $defaults;
            }

            $stored = json_decode((string) File::get($path), true);
        } catch (Throwable) {
            return $defaults;
        }

        if (! is_array($stored)) {
            return $defaults;
        }

        $known = collect(DeploySeeders::all())->pluck('name');

        return [
            'options' => collect(self::KEYS)
                ->mapWithKeys(fn (string $key): array => [
                    $key => (bool) ($stored['options'][$key] ?? true),
                ])
                ->all(),
            // زارعٌ حُذف من المستودع بعد آخر نشرة لا يُعاد اقتراحه.
            'seeders' => collect($stored['seeders'] ?? DeploySeeders::DEFAULT)
                ->filter(fn ($name): bool => is_string($name) && $known->contains($name))
                ->values()
                ->all(),
            'branch' => is_string($stored['branch'] ?? null) && $stored['branch'] !== '' ? $stored['branch'] : null,
        ];
    }

    /**
     * @param  array<string, bool>  $options
     * @param  list<string>  $seeders
     */
    public static function remember(array $options, array $seeders, ?string $branch): void
    {
        try {
            File::ensureDirectoryExists(dirname(self::path()));

            File::put(self::path(), json_encode([
                'options' => collect(self::KEYS)->mapWithKeys(fn (string $key): array => [
                    $key => (bool) ($options[$key] ?? true),
                ])->all(),
                'seeders' => array_values($seeders),
                'branch' => $branch,
                'saved_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } catch (Throwable) {
            // تفضيلٌ لا يستحقّ إسقاط نشرةٍ لأنه لم يُكتب.
        }
    }

    private static function path(): string
    {
        return storage_path('app/deploy-options.json');
    }
}
