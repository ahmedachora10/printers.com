<?php

use App\Models\User;
use App\Support\ComposerBinary;
use App\Support\DeploySeeders;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * faker حزمةُ تطوير، ودالّة fake() لا تُعرَّف إلا إن وُجد ‎\Faker\Factory‎ عند
 * الإقلاع. فزارعُ المصانع على خادمٍ ثُبّت بـ ‎--no-dev‎ يقع في «Call to
 * undefined function fake()» بعد الهجرات — وهو ما وقع في نشرة 2 سبتمبر 2026.
 *
 * والعلاج تثبيتُ حزم التطوير قبل الزرع؛ فالمنع لم يعد لغياب faker، بل لتعذّر
 * جلبها. ولا سبيل إلى نزع faker من جهاز التطوير، فتُحاكى غيبتها؛ وحضور
 * composer يُثبَّت بدل أن يُترك لصدفة الجهاز.
 */
afterEach(function () {
    DeploySeeders::assumeFakerAvailable(null);
    ComposerBinary::forget();
});

function admin(): User
{
    test()->withoutVite();
    test()->seed(RolesAndPermissionsSeeder::class);
    config()->set('deploy.ui.enabled', true);

    $admin = User::factory()->create();
    $admin->addRole('super-admin');

    return $admin;
}

it('يُعلّم زارع المصانع غير قابلٍ للتشغيل حين تغيب faker ويغيب composer', function () {
    DeploySeeders::assumeFakerAvailable(false);
    ComposerBinary::assume(null);

    $seeders = collect(DeploySeeders::all())->keyBy('name');

    expect($seeders['UserSeeder']['runnable'])->toBeFalse()
        ->and($seeders['DatabaseSeeder']['runnable'])->toBeFalse()
        // المرجعيّ لا يمسّ مصنعاً، فلا شأن له بحزم التطوير.
        ->and($seeders['RolesAndPermissionsSeeder']['runnable'])->toBeTrue()
        ->and($seeders['CitySeeder']['runnable'])->toBeTrue();
});

it('يُبقيه متاحاً حين تغيب faker ويحضر composer ليجلبها', function () {
    DeploySeeders::assumeFakerAvailable(false);
    ComposerBinary::assume('/usr/local/bin/composer');

    $seeders = collect(DeploySeeders::all())->keyBy('name');

    expect($seeders['UserSeeder']['runnable'])->toBeTrue()
        ->and(DeploySeeders::blocked(['UserSeeder']))->toBe([])
        ->and(DeploySeeders::needsDevInstall(['UserSeeder']))->toBeTrue()
        // المرجعيّ لا يستدعي تثبيتاً.
        ->and(DeploySeeders::needsDevInstall(['CitySeeder']))->toBeFalse();
});

it('يمنعه حين يحضر composer لكنّ خطوته متخطّاة', function () {
    DeploySeeders::assumeFakerAvailable(false);
    ComposerBinary::assume('/usr/local/bin/composer');

    expect(DeploySeeders::blocked(['UserSeeder'], canInstall: false))
        ->toBe(['مستخدمون تجريبيون']);
});

it('لا يحتاج تثبيتاً حين تكون faker مثبّتة', function () {
    expect(DeploySeeders::fakerAvailable())->toBeTrue()
        ->and(DeploySeeders::needsDevInstall(['UserSeeder', 'DatabaseSeeder']))->toBeFalse()
        ->and(DeploySeeders::blocked(['UserSeeder', 'DatabaseSeeder']))->toBe([]);
});

it('يُبلّغ عن السبب بلغةٍ تُفهم', function () {
    expect(DeploySeeders::unavailableReason())
        ->toContain('--no-dev')
        ->toContain('fakerphp/faker');
});

it('يعلن في الخطة أنّ حزم التطوير ستُثبَّت', function () {
    DeploySeeders::assumeFakerAvailable(false);
    ComposerBinary::assume('/usr/local/bin/composer');

    $this->artisan('app:deploy', [
        '--dry-run' => true,
        '--seeders' => 'UserSeeder',
        '--allow-demo-seeders' => true,
    ])->expectsOutputToContain('مع حزم التطوير');
});

it('يقف قبل أن يُغلق الموقع حين لا سبيل إلى التثبيت', function () {
    DeploySeeders::assumeFakerAvailable(false);
    ComposerBinary::assume(null);

    $this->artisan('app:deploy', [
        '--dry-run' => true,
        '--seeders' => 'DatabaseSeeder',
        '--allow-demo-seeders' => true,
    ])
        ->expectsOutputToContain('زارعات متعذّرة')
        ->assertFailed();
});

it('يقف أيضاً حين تُتخطّى خطوة composer', function () {
    DeploySeeders::assumeFakerAvailable(false);
    ComposerBinary::assume('/usr/local/bin/composer');

    $this->artisan('app:deploy', [
        '--dry-run' => true,
        '--skip-composer' => true,
        '--seeders' => 'DatabaseSeeder',
        '--allow-demo-seeders' => true,
    ])
        ->expectsOutputToContain('خطوة composer متخطّاة')
        ->assertFailed();
});

it('يقبل طلب الشاشة حين يمكن جلب faker', function () {
    $admin = admin();

    DeploySeeders::assumeFakerAvailable(false);
    ComposerBinary::assume('/usr/local/bin/composer');

    $this->actingAs($admin)
        ->post(route('deployment.run'), [
            'dryRun' => true,
            'options' => ['seed' => true, 'composer' => true],
            'seeders' => ['DatabaseSeeder'],
            'demoConfirmed' => true,
        ])
        ->assertOk();
});

it('يردّ طلب الشاشة حين تُطفأ خطوة composer', function () {
    $admin = admin();

    DeploySeeders::assumeFakerAvailable(false);
    ComposerBinary::assume('/usr/local/bin/composer');

    $this->actingAs($admin)
        ->postJson(route('deployment.run'), [
            'dryRun' => true,
            'options' => ['seed' => true, 'composer' => false],
            'seeders' => ['DatabaseSeeder'],
            'demoConfirmed' => true,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('seeders');
});

it('يردّ الخطأ JSON لا صفحةً كاملة، مهما كانت ترويسة Accept', function () {
    $admin = admin();

    DeploySeeders::assumeFakerAvailable(false);
    ComposerBinary::assume(null);

    // الشاشة تطلب النصّ لأن النشر الناجح يُدفَق نصّاً؛ فلا يجوز أن يتحوّل
    // خطأ التحقّق إلى إعادة توجيهٍ تُدفع إلى صندوق المخرجات صفحةً كاملة.
    $response = $this->actingAs($admin)->post(
        route('deployment.run'),
        [
            'dryRun' => true,
            'options' => ['seed' => true],
            'seeders' => ['DatabaseSeeder'],
            'demoConfirmed' => true,
        ],
        ['Accept' => 'text/plain, application/json']
    );

    $response->assertStatus(422)
        ->assertHeader('content-type', 'application/json')
        ->assertJsonValidationErrors('seeders');

    expect($response->getContent())->not->toContain('<!DOCTYPE html>');
});

it('يردّ منعَ الإذن JSON أيضاً', function () {
    config()->set('deploy.ui.enabled', true);

    $this->post(route('deployment.run'), ['dryRun' => true], ['Accept' => 'text/plain'])
        ->assertForbidden()
        ->assertJson(['message' => 'لا إذن لك بتشغيل النشر.']);
});

it('يعرض في الشاشة حال faker وcomposer', function () {
    $admin = admin();

    DeploySeeders::assumeFakerAvailable(false);
    ComposerBinary::assume('/usr/local/bin/composer');

    $this->actingAs($admin)
        ->get(route('deployment.index'))
        ->assertInertia(fn ($page) => $page
            ->where('environment.fakerInstalled', false)
            ->where('environment.composerAvailable', true)
            ->etc()
        );
});
