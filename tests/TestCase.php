<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * حاجز قاعدة بيانات الاختبارات.
     *
     * `phpunit.xml` يوجّه الاختبارات إلى sqlite في الذاكرة (`:memory:`)، لكن
     * `RefreshDatabase` ينفّذ `migrate:fresh` على أي قاعدة يجدها. فلو بقي
     * `bootstrap/cache/config.php` قديماً — أو ظهر `.env.testing` يشير إلى ملف —
     * فستُمسح قاعدة التطوير `database/database.sqlite` بلا إنذار.
     *
     * والفحص هنا لا في `tests/Pest.php`: ذاك يُحمَّل قبل أن يطبّق PHPUnit عناصر
     * `<env>`، فلا يرى القاعدة الفعلية بل NULL فيوقف كل تشغيل. وهذه الدالة
     * تُستدعى بعد بناء التطبيق وقبل `setUpTraits()`، أي قبل `RefreshDatabase`
     * بالضبط.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $connection = $this->app['config']->get('database.default');
        $database = $this->app['config']->get("database.connections.{$connection}.database");

        $isolated = $database === ':memory:'
            || (is_string($database) && str_contains(strtolower($database), 'test'));

        if ($isolated) {
            return;
        }

        fwrite(STDERR, PHP_EOL.'  توقّفت الاختبارات: قاعدة البيانات ليست معزولة.'.PHP_EOL
            .'  الاتصال: '.var_export($connection, true).'  |  القاعدة: '.var_export($database, true).PHP_EOL
            .'  تشغيل RefreshDatabase عليها يعني مسح بيانات العمل.'.PHP_EOL
            .'  الحل: php artisan config:clear  ثم أعد التشغيل (المتوقع DB_DATABASE=:memory:).'.PHP_EOL.PHP_EOL);

        exit(1);
    }
}
