<?php

use App\Models\User;
use App\Support\DeploySeeders;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * faker حزمةُ تطوير، ودالّة fake() لا تُعرَّف إلا إن وُجد ‎\Faker\Factory‎.
 * فزارعُ المصانع على خادمٍ ثُبّت بـ ‎--no-dev‎ يقع في «Call to undefined
 * function fake()» بعد الهجرات — وهو ما وقع في نشرة 2 سبتمبر 2026.
 *
 * لا سبيل إلى نزع faker من جهاز التطوير، فتُحاكى غيبته.
 */
afterEach(function () {
    DeploySeeders::assumeFakerAvailable(null);
});

it('يُعلّم زارع المصانع غير قابلٍ للتشغيل حين يغيب faker', function () {
    DeploySeeders::assumeFakerAvailable(false);

    $seeders = collect(DeploySeeders::all())->keyBy('name');

    expect($seeders['UserSeeder']['runnable'])->toBeFalse()
        ->and($seeders['DatabaseSeeder']['runnable'])->toBeFalse()
        // المرجعيّ لا يمسّ مصنعاً، فلا شأن له بحزم التطوير.
        ->and($seeders['RolesAndPermissionsSeeder']['runnable'])->toBeTrue()
        ->and($seeders['CitySeeder']['runnable'])->toBeTrue();
});

it('يعدّها كلها قابلةً للتشغيل حين يكون faker مثبّتاً', function () {
    expect(DeploySeeders::fakerAvailable())->toBeTrue()
        ->and(DeploySeeders::blocked(['UserSeeder', 'DatabaseSeeder']))->toBe([]);
});

it('يجمع المتعذّر بأسمائه المقروءة', function () {
    DeploySeeders::assumeFakerAvailable(false);

    expect(DeploySeeders::blocked(['RolesAndPermissionsSeeder', 'UserSeeder']))
        ->toBe(['مستخدمون تجريبيون']);
});

it('يُبلّغ عن السبب بلغةٍ تُفهم', function () {
    expect(DeploySeeders::unavailableReason())
        ->toContain('--no-dev')
        ->toContain('fakerphp/faker');
});

it('يقف قبل أن يُغلق الموقع بدل أن يسقط بعد الهجرات', function () {
    DeploySeeders::assumeFakerAvailable(false);

    $this->artisan('app:deploy', [
        '--dry-run' => true,
        '--seeders' => 'DatabaseSeeder',
        '--allow-demo-seeders' => true,
    ])
        ->expectsOutputToContain('زارعات متعذّرة')
        ->assertFailed();
});

it('يردّ طلب الشاشة ولو أُكِّد صراحةً', function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);
    config()->set('deploy.ui.enabled', true);

    DeploySeeders::assumeFakerAvailable(false);

    $admin = User::factory()->create();
    $admin->addRole('super-admin');

    $this->actingAs($admin)
        ->postJson(route('deployment.run'), [
            'dryRun' => true,
            'options' => ['seed' => true],
            'seeders' => ['DatabaseSeeder'],
            'demoConfirmed' => true,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('seeders');
});

it('يعرض في الشاشة أنّ الزارع غير متاح', function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);
    config()->set('deploy.ui.enabled', true);

    DeploySeeders::assumeFakerAvailable(false);

    $admin = User::factory()->create();
    $admin->addRole('super-admin');

    $response = $this->actingAs($admin)->get(route('deployment.index'));

    $seeders = collect($response->viewData('page')['props']['seeders'])->keyBy('name');

    expect($seeders['UserSeeder']['runnable'])->toBeFalse()
        ->and($seeders['SettingSeeder']['runnable'])->toBeTrue();
});
