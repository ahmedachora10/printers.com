<?php

use App\Models\User;
use App\Support\DeployAccess;
use App\Support\DeployPreferences;
use App\Support\PhpBinary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    config()->set('deploy.ui.enabled', true);
    config()->set('deploy.token', 'secret-token');

    File::delete(storage_path('app/deploy-options.json'));

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->addRole('super-admin');
});

afterEach(function () {
    File::delete(storage_path('app/deploy-options.json'));

    // المُفسِّر يُحفظ في ذاكرة الصنف، فيتسرّب بين الاختبارات إن لم يُنسَ.
    PhpBinary::forget();
});

it('يُخفي الشاشة ما لم تُرفع رايتها ولو كان الداخل سوبر أدمن', function () {
    config()->set('deploy.ui.enabled', false);

    $this->actingAs($this->superAdmin)->get(route('deployment.index'))->assertNotFound();
    $this->actingAs($this->superAdmin)->post(route('deployment.run'))->assertNotFound();
});

it('يعرض نموذج المفتاح لمن ليس سوبر أدمن', function () {
    $employee = User::factory()->create();
    $employee->addRole('employee');

    $this->actingAs($employee)
        ->get(route('deployment.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('deployment/unlock')->where('configured', true));
});

it('يعرض نموذج المفتاح للزائر بلا حساب', function () {
    $this->get(route('deployment.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('deployment/unlock'));
});

it('يمنع التنفيذ على من لم يفتح بالمفتاح', function () {
    $this->post(route('deployment.run'), ['dryRun' => true])->assertForbidden();
});

it('يرفض المفتاح الخاطئ ويسجّل المحاولة', function () {
    $this->post(route('deployment.unlock'), ['token' => 'wrong'])
        ->assertSessionHasErrors('token');

    expect(session()->has(DeployAccess::SESSION_KEY))->toBeFalse();
    $this->assertDatabaseHas('activity_log', ['log_name' => 'deploy']);
});

it('لا يفتح بمفتاحٍ فارغ حين لا مفتاح مضبوطاً على الخادم', function () {
    config()->set('deploy.token', null);

    $this->get(route('deployment.index'))
        ->assertInertia(fn ($page) => $page->component('deployment/unlock')->where('configured', false));

    $this->post(route('deployment.unlock'), ['token' => ''])->assertSessionHasErrors('token');
});

it('يفتح الشاشة للزائر بالمفتاح الصحيح', function () {
    $this->post(route('deployment.unlock'), ['token' => 'secret-token'])
        ->assertRedirect(route('deployment.index'));

    $this->get(route('deployment.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('deployment/index')
            ->where('standalone', true)
            ->where('unlockedByToken', true)
            ->etc()
        );
});

it('يُنفّذ لحامل المفتاح ويُسجّله بابه في السجلّ', function () {
    $this->post(route('deployment.unlock'), ['token' => 'secret-token']);

    $response = $this->post(route('deployment.run'), ['dryRun' => true]);
    $response->assertOk();

    // سجلّ النشاط يُكتب داخل الدفق، فلا يوجد قبل أن يُقرأ المحتوى.
    $response->streamedContent();

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'deploy',
        'properties->via' => 'ui-token',
    ]);
});

it('يُغلق الجلسة المفتوحة بالمفتاح', function () {
    $this->post(route('deployment.unlock'), ['token' => 'secret-token']);

    $this->delete(route('deployment.lock'))->assertRedirect(route('deployment.index'));

    $this->get(route('deployment.index'))
        ->assertInertia(fn ($page) => $page->component('deployment/unlock'));
});

it('يبقى معدوماً لحامل المفتاح ما دامت الراية مطفأة', function () {
    config()->set('deploy.ui.enabled', false);

    $this->post(route('deployment.unlock'), ['token' => 'secret-token'])->assertNotFound();
    $this->get(route('deployment.index'))->assertNotFound();
});

it('يعرض مُفسِّر العمليات الفرعية وحاله', function () {
    $this->actingAs($this->superAdmin)
        ->get(route('deployment.index'))
        ->assertInertia(fn ($page) => $page
            ->where('environment.phpBinary', PHP_BINARY)
            ->where('environment.phpBinaryOk', true)
            ->where('environment.phpBinaryNote', null)
            ->etc()
        );
});

it('يُنبّه في الشاشة إلى مسار مُفسِّرٍ مضبوطٍ لا وجود له', function () {
    config()->set('deploy.php_binary', '/opt/cpanel/ea-php83/root/usr/bin/php-missing');
    PhpBinary::forget();

    $this->actingAs($this->superAdmin)
        ->get(route('deployment.index'))
        ->assertInertia(fn ($page) => $page
            ->where('environment.phpBinaryOk', false)
            ->where('environment.phpBinaryNote', fn ($note) => str_contains((string) $note, 'غير موجود'))
            ->etc()
        );
});

it('يرى السوبر أدمن الشاشة داخل التطبيق لا وحدها', function () {
    $this->actingAs($this->superAdmin)
        ->get(route('deployment.index'))
        ->assertInertia(fn ($page) => $page
            ->component('deployment/index')
            ->where('standalone', false)
            ->where('unlockedByToken', false)
            ->etc()
        );
});

it('يعرض الزارعات مع تمييز التجريبية منها', function () {
    $this->actingAs($this->superAdmin)
        ->get(route('deployment.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('deployment/index')
            ->where('preferences.seeders', ['RolesAndPermissionsSeeder'])
            ->has('environment.env')
            ->has('seeders', fn ($seeders) => $seeders->each(fn ($seeder) => $seeder
                ->has('name')
                ->has('label')
                ->has('demo')
                ->etc()
            ))
        );
});

it('يميّز زارع الصلاحيات كآمن وزارع المستخدمين كتجريبي', function () {
    $response = $this->actingAs($this->superAdmin)->get(route('deployment.index'));

    $seeders = collect($response->viewData('page')['props']['seeders'])->keyBy('name');

    expect($seeders['RolesAndPermissionsSeeder']['demo'])->toBeFalse()
        ->and($seeders['UserSeeder']['demo'])->toBeTrue()
        ->and($seeders['DatabaseSeeder']['demo'])->toBeTrue();
});

it('يرفض زارعاً غير معروف', function () {
    $this->actingAs($this->superAdmin)
        ->postJson(route('deployment.run'), [
            'dryRun' => true,
            'options' => ['seed' => true],
            'seeders' => ['DropEverythingSeeder'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('seeders.0');
});

it('يرفض زارع البيانات التجريبية بلا تأكيدٍ صريح', function () {
    $this->actingAs($this->superAdmin)
        ->postJson(route('deployment.run'), [
            'dryRun' => true,
            'options' => ['seed' => true],
            'seeders' => ['UserSeeder'],
            'demoConfirmed' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('seeders');
});

it('يقبله بعد التأكيد', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('deployment.run'), [
            'dryRun' => true,
            'options' => ['seed' => true],
            'seeders' => ['UserSeeder'],
            'demoConfirmed' => true,
        ])
        ->assertOk();
});

it('يُدفق خطة النشر في العرض المجرّد', function () {
    $response = $this->actingAs($this->superAdmin)->post(route('deployment.run'), [
        'dryRun' => true,
        'options' => ['pull' => false, 'seed' => true],
        'seeders' => ['RolesAndPermissionsSeeder'],
    ]);

    $response->assertOk();

    expect($response->streamedContent())
        ->toContain('لن يُنفَّذ شيء')
        ->toContain('الأدوار والصلاحيات')
        ->toContain('== اكتمل النشر ==');
});

it('يحفظ آخر اختيارٍ ليجده في المرة التالية', function () {
    $this->actingAs($this->superAdmin)->post(route('deployment.run'), [
        'dryRun' => true,
        'branch' => 'main',
        'options' => ['pull' => true, 'composer' => false, 'backup' => true, 'seed' => true],
        'seeders' => ['RolesAndPermissionsSeeder', 'CitySeeder'],
    ])->assertOk();

    $stored = DeployPreferences::load();

    expect($stored['options']['composer'])->toBeFalse()
        ->and($stored['options']['pull'])->toBeTrue()
        ->and($stored['seeders'])->toBe(['RolesAndPermissionsSeeder', 'CitySeeder'])
        ->and($stored['branch'])->toBe('main');
});

it('يرفض اسم فرعٍ غير صالح', function () {
    $this->actingAs($this->superAdmin)
        ->postJson(route('deployment.run'), [
            'dryRun' => true,
            'branch' => 'main;rm -rf /',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('branch');
});

it('يمنع نشراً ثانياً ما دام الأول يعمل', function () {
    Cache::lock('deploy:running', 60)->get();

    $this->actingAs($this->superAdmin)
        ->post(route('deployment.run'), ['dryRun' => true])
        ->assertStatus(409);
});
