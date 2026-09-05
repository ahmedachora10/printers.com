<?php

use App\Actions\System\BackupDatabaseAction;
use App\Models\City;
use App\Models\User;
use App\Support\ComposerBinary;
use App\Support\DeploySeeders;
use App\Support\DeployTasks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    config()->set('deploy.ui.enabled', true);
    config()->set('deploy.token', 'secret-token');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->addRole('super-admin');
});

afterEach(function () {
    DeploySeeders::assumeFakerAvailable(null);
    ComposerBinary::forget();
});

it('يُخفي الشاشة ما لم تُرفع راية النشر', function () {
    config()->set('deploy.ui.enabled', false);

    $this->actingAs($this->superAdmin)->get(route('deployment.commands'))->assertNotFound();
    $this->actingAs($this->superAdmin)->post(route('deployment.commands.run'))->assertNotFound();
});

it('يعرض نموذج المفتاح لمن لا إذن له', function () {
    $this->get(route('deployment.commands'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('deployment/unlock'));
});

it('يمنع التنفيذ على من لا إذن له', function () {
    $this->post(route('deployment.commands.run'), ['task' => 'migrate-status'])
        ->assertForbidden()
        ->assertJson(['message' => 'لا إذن لك بتشغيل الأوامر.']);
});

it('يعرض الأوامر مجموعةً مع وصفها', function () {
    $this->actingAs($this->superAdmin)
        ->get(route('deployment.commands'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('deployment/commands')
            ->has('groups')
            ->has('tasks', count(DeployTasks::keys()))
            ->has('seeders')
            ->has('branches')
            ->etc()
        );
});

it('يرفض أمراً خارج القائمة المغلقة', function () {
    $this->actingAs($this->superAdmin)
        ->postJson(route('deployment.commands.run'), ['task' => 'migrate:fresh'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('task');
});

it('يرفض أمراً بلا اسم', function () {
    $this->actingAs($this->superAdmin)
        ->postJson(route('deployment.commands.run'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('task');
});

it('يُشغّل أمراً هيّناً ويُدفق مخرجاته', function () {
    $response = $this->actingAs($this->superAdmin)
        ->post(route('deployment.commands.run'), ['task' => 'migrate-status']);

    $response->assertOk();

    expect($response->streamedContent())
        ->toContain('حالة الهجرات')
        ->toContain('== اكتمل النشر ==');
});

it('يُسجّل الأمر في سجلّ النشاط باسمه', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('deployment.commands.run'), ['task' => 'optimize-clear'])
        ->streamedContent();

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'deploy',
        'properties->key' => 'optimize-clear',
        'properties->via' => 'ui',
    ]);
});

it('يأخذ نسخةً احتياطية قبل الهجرات', function () {
    // قاعدة الاختبار في الذاكرة فلا ملفَّ يُنسخ؛ والمقصود هنا الترتيب لا
    // النسخة نفسها — أن تُطلب قبل أن تُمسّ البنية.
    $this->mock(BackupDatabaseAction::class)
        ->shouldReceive('handle')
        ->once()
        ->andReturn(base_path('storage/app/backups/db-test.sql.gz'));

    $response = $this->actingAs($this->superAdmin)
        ->post(route('deployment.commands.run'), ['task' => 'migrate']);

    expect($response->streamedContent())
        ->toContain('نسخة احتياطية:')
        ->toContain('== اكتمل النشر ==');
});

it('يقف عن الهجرة إن تعذّرت النسخة الاحتياطية', function () {
    // النسخة شبكةُ أمانٍ لا زينة: تعذُّرها يعني ألّا يُمسّ شيء. وقاعدة
    // الاختبار في الذاكرة، فالتعذُّر هنا حقيقيٌّ لا مُصطنع.
    $response = $this->actingAs($this->superAdmin)
        ->post(route('deployment.commands.run'), ['task' => 'migrate']);

    expect($response->streamedContent())
        ->toContain('== فشل النشر ==')
        ->not->toContain('Migrating');
});

it('يشترك مع النشر في القفل نفسه', function () {
    Cache::lock('deploy:running', 60)->get();

    $this->actingAs($this->superAdmin)
        ->post(route('deployment.commands.run'), ['task' => 'migrate-status'])
        ->assertStatus(409);
});

it('يمنع النشر ما دام أمرٌ مفردٌ يعمل', function () {
    Cache::lock('deploy:running', 60)->get();

    $this->actingAs($this->superAdmin)
        ->post(route('deployment.run'), ['dryRun' => true])
        ->assertStatus(409);
});

it('يشترط اختيار زارعٍ واحدٍ على الأقل', function () {
    $this->actingAs($this->superAdmin)
        ->postJson(route('deployment.commands.run'), ['task' => 'seed', 'seeders' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('seeders');
});

it('يُشغّل الزارع المختار وحده دون بقية خطوات النشر', function () {
    $this->mock(BackupDatabaseAction::class)
        ->shouldReceive('handle')
        ->andReturn(base_path('storage/app/backups/db-test.sql.gz'));

    $response = $this->actingAs($this->superAdmin)->post(route('deployment.commands.run'), [
        'task' => 'seed',
        'seeders' => ['CitySeeder'],
    ]);

    $response->assertOk();

    expect($response->streamedContent())
        ->toContain('زرع: المدن')
        ->toContain('== اكتمل النشر ==')
        // لا سحبَ كودٍ ولا حزم ولا صيانة — هذا هو المقصود من الأمر المفرد.
        ->not->toContain('سحب الكود');

    expect(City::count())->toBeGreaterThan(0);
});

it('يشترط التأكيد قبل زارع البيانات التجريبية', function () {
    $this->actingAs($this->superAdmin)
        ->postJson(route('deployment.commands.run'), [
            'task' => 'seed',
            'seeders' => ['UserSeeder'],
            'demoConfirmed' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('seeders');
});

it('يرفض زارعاً تجريبياً متعذّراً هنا', function () {
    DeploySeeders::assumeFakerAvailable(false);
    ComposerBinary::assume(null);

    $this->actingAs($this->superAdmin)
        ->postJson(route('deployment.commands.run'), [
            'task' => 'seed',
            'seeders' => ['UserSeeder'],
            'demoConfirmed' => true,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('seeders');
});

it('يرفض فرعاً غير موجود', function () {
    $this->actingAs($this->superAdmin)
        ->postJson(route('deployment.commands.run'), [
            'task' => 'loyalty-expire',
            'branch' => 99999,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('branch');
});

it('لا يذكر أمراً واحداً بأمرٍ حرّ في القائمة', function () {
    // القائمة مغلقة عمداً: أيّ مفتاحٍ فيها يقابل أمراً مكتوباً في الشيفرة،
    // ولا يُقبل من الطلب إلا المفتاح.
    foreach (DeployTasks::all() as $key => $task) {
        expect($task['command'])->not->toContain(' ')
            ->and($key)->toMatch('/^[a-z-]+$/');
    }
});
