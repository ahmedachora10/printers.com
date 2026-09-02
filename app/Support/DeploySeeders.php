<?php

namespace App\Support;

use Faker\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * جردُ الزارعات المتاحة وتمييز ما يصلح للإنتاج ممّا لا يصلح.
 *
 * الزارع نوعان: زارعُ بياناتٍ مرجعية — مدنٌ ووحداتٌ وصلاحيات — يُعاد تشغيله
 * بلا ضرر لأنه firstOrCreate، وزارعُ بياناتٍ تجريبية يخلق عملاء وفواتير من
 * المصانع. الثاني على فرعٍ عامل كارثة، فيُعلَّم ويُطلب تأكيدٌ ثانٍ قبله.
 *
 * التمييز يُقرأ من المصدر لا من قائمةٍ مكتوبة بيد: كل زارعٍ يستعمل مصنعاً أو
 * fake() أو يستدعي زارعاً آخر فهو تجريبي. وما شكّ فيه يُعدّ تجريبياً، فالخطأ
 * في جانب الحذر أرخص من عكسه.
 */
class DeploySeeders
{
    /**
     * أسماءٌ عربية لما نعرفه؛ وما استُجدّ يظهر باسم صنفه حتى يُسمّى هنا.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'RolesAndPermissionsSeeder' => 'الأدوار والصلاحيات',
        'CitySeeder' => 'المدن',
        'ProductUnitSeeder' => 'وحدات القياس',
        'PaymentMethodSeeder' => 'طرق الدفع',
        'SettingSeeder' => 'الإعدادات الافتراضية',
        'BranchSeeder' => 'فروع تجريبية',
        'UserSeeder' => 'مستخدمون تجريبيون',
        'CustomerSeeder' => 'عملاء تجريبيون',
        'ProductSeeder' => 'منتجات تجريبية',
        'ProductCategorySeeder' => 'فئات منتجات تجريبية',
        'ServiceTemplateSeeder' => 'قوالب خدمات تجريبية',
        'CouponSeeder' => 'كوبونات تجريبية',
        'DatabaseSeeder' => 'الزارع الجامع (يستدعي البقية)',
    ];

    /**
     * ما يُختار مسبقاً في الواجهة: الصلاحيات وحدها، فهي التي يُعطّل غيابُها
     * شاشةً في إصدارٍ جديد. وما عداها قرارٌ يُتّخذ لا افتراضٌ يمرّ.
     *
     * @var list<string>
     */
    public const DEFAULT = ['RolesAndPermissionsSeeder'];

    private static ?bool $fakerAvailable = null;

    /**
     * زارعُ البيانات التجريبية يستحيل على خادم الإنتاج، لا يُكره عليه.
     *
     * fakerphp/faker حزمةُ تطوير، و‎composer install --no-dev‎ لا يُثبّتها؛
     * ودالّة fake() نفسها لا تُعرَّف إلا إن وُجد ‎\Faker\Factory‎ — انظر
     * helpers.php في إطار لارافل. فالمصنع على الإنتاج يقع في
     * «Call to undefined function fake()» في منتصف النشر، بعد الهجرات.
     */
    public static function fakerAvailable(): bool
    {
        return self::$fakerAvailable ??= class_exists(Factory::class);
    }

    /**
     * محاكاة خادمٍ بلا حزم تطوير — لا سبيل إلى نزع faker من هذا الجهاز،
     * والحارس يستحقّ اختباراً. null يُعيده إلى الكشف التلقائي.
     */
    public static function assumeFakerAvailable(?bool $available): void
    {
        self::$fakerAvailable = $available;
    }

    public static function unavailableReason(): string
    {
        return 'حزم التطوير غير مثبّتة هنا (composer install --no-dev)، وزارعات البيانات التجريبية تحتاج fakerphp/faker.';
    }

    /**
     * ما لا يمكن تشغيله من المطلوب — يُسأل قبل أن يُلمس شيء.
     *
     * @param  list<string>  $names
     * @return list<string> أسماء الزارعات المتعذّرة
     */
    public static function blocked(array $names): array
    {
        if (self::fakerAvailable()) {
            return [];
        }

        return collect(self::resolve($names))
            ->where('demo', true)
            ->pluck('label')
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, class: class-string, label: string, demo: bool, runnable: bool}>
     */
    public static function all(): array
    {
        $directory = database_path('seeders');

        if (! is_dir($directory)) {
            return [];
        }

        $seeders = [];

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $name = $file->getFilenameWithoutExtension();
            $class = 'Database\\Seeders\\'.$name;

            if (! class_exists($class)) {
                continue;
            }

            $demo = self::looksLikeDemoData((string) File::get($file->getPathname()));

            $seeders[] = [
                'name' => $name,
                'class' => $class,
                'label' => self::LABELS[$name] ?? Str::headline(Str::before($name, 'Seeder')),
                'demo' => $demo,
                'runnable' => ! $demo || self::fakerAvailable(),
            ];
        }

        usort($seeders, fn (array $a, array $b): int => [$a['demo'], $a['label']] <=> [$b['demo'], $b['label']]);

        return $seeders;
    }

    /**
     * تحويل أسماءٍ واردة من الخارج إلى أصناف. الاسم يُطابَق بما جُرد فعلاً،
     * فلا يمرّ صنفٌ اخترعه الطلب — الزارع يُنفَّذ بصلاحية الخادم كاملةً.
     *
     * @param  list<string>  $names
     * @return list<array{name: string, class: class-string, label: string, demo: bool, runnable: bool}>
     */
    public static function resolve(array $names): array
    {
        $known = collect(self::all())->keyBy('name');

        return collect($names)
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->unique()
            ->map(function (string $name) use ($known): array {
                $seeder = $known->get($name) ?? $known->get(class_basename($name));

                if ($seeder === null) {
                    throw new \InvalidArgumentException("زارعٌ غير معروف: {$name}");
                }

                return $seeder;
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $names
     */
    public static function anyDemo(array $names): bool
    {
        return collect(self::resolve($names))->contains(fn (array $seeder): bool => $seeder['demo']);
    }

    private static function looksLikeDemoData(string $source): bool
    {
        return (bool) preg_match('/factory\s*\(|fake\s*\(\)|faker|\$this->call\s*\(/i', $source);
    }
}
