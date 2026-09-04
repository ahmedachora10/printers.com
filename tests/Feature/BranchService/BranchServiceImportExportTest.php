<?php

use App\Enums\Roles;
use App\Exports\BranchServicesExport;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * تصدير/استيراد خدمات الفرع في ورقتين.
 *
 * كل ورقةٍ هنا تُبنى من عناوين التصدير نفسها لا من نصٍّ معاد كتابته: العناوين
 * العربية تُسلَّك إلى لاتينية عند القراءة، وهذا بالضبط ما جعل استيرادات سابقة
 * تُبلّغ بالنجاح وقد كتبت صفراً من الصفوف.
 *
 * @param  array<int, array{title: string, headings: array<int, string>, rows: array<int, array<int, mixed>>}>  $sheets
 */
function branchServicesWorkbook(array $sheets): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);

    foreach ($sheets as $index => $sheet) {
        $worksheet = $spreadsheet->createSheet($index);
        $worksheet->setTitle($sheet['title']);
        // strictNullComparison: بدونها تُعدّ الخليّة التي قيمتها 0 فارغةً فتُحذف،
        // وعمود «نشط» كلّه أصفار وآحاد.
        $worksheet->fromArray([$sheet['headings'], ...$sheet['rows']], null, 'A1', true);
    }

    $path = tempnam(sys_get_temp_dir(), 'branch-services').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'branch-services.xlsx', null, null, true);
}

/**
 * @param  array<int, array<int, mixed>>  $services
 * @param  array<int, array<int, mixed>>|null  $commissions
 */
function branchServicesSheet(array $services, ?array $commissions = null): UploadedFile
{
    $sheets = [[
        'title' => BranchServicesExport::SERVICES_SHEET,
        'headings' => BranchServicesExport::serviceHeadings(),
        'rows' => $services,
    ]];

    if ($commissions !== null) {
        $sheets[] = [
            'title' => BranchServicesExport::COMMISSIONS_SHEET,
            'headings' => BranchServicesExport::commissionHeadings(),
            'rows' => $commissions,
        ];
    }

    return branchServicesWorkbook($sheets);
}

/**
 * صفُّ خدمةٍ كامل الأعمدة بقيمٍ افتراضية معقولة، يُعدَّل منه ما يخصّ الاختبار.
 *
 * الاتحاد `+` لا النثر `...`: النثر يعيد ترقيم المفاتيح الصحيحة فيلحق البدائل
 * بذيل الصف بدل أن تحلّ محلّ أعمدتها. وksort بعده لأن الاتحاد يحفظ المفاتيح ولا
 * يرتّبها — فبديلُ العمود الثاني كان يخرج أولاً ويصير اسمَ الخدمة.
 *
 * @param  array<int, mixed>  $overrides
 * @return array<int, mixed>
 */
function serviceRow(string $name, array $overrides = []): array
{
    $row = $overrides + [
        0 => $name,
        1 => '10.00',   // نسبة العمولة
        2 => '5.00',    // أقصى خصم
        3 => '',        // أعلى سعر
        4 => '',        // أقل سعر
        5 => 'بالوحدة', // نوع التسعير
        6 => '0.00',    // سعر المتر
        7 => '0.00',    // عمولة الوكيل للمتر
        8 => 0,         // تحضير
        9 => 0,         // لها خامات
        10 => '0.00',   // تكلفة الخامات
        11 => '',       // أمثلة الملاحظات
        12 => 1,        // نشط
    ];

    ksort($row);

    return array_values($row);
}

/**
 * خدمةٌ مربوطة بفرع. الربط يمرّ بالعلاقة لا بـ`BranchService::create()`:
 * الموديل Pivot، فالإنشاء المباشر لا يُرجع مفتاحاً يُبنى عليه سطر عمولة.
 *
 * @param  array<string, mixed>  $pivot
 */
function attachedService(Branch $branch, string $name, array $pivot = []): BranchService
{
    $template = ServiceTemplate::factory()->create(['name' => $name]);
    $template->branches()->attach($branch->id, $pivot);

    return BranchService::query()
        ->where('branch_id', $branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->addRole(Roles::BRANCH_ADMIN->value);
    $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
    $this->admin->update(['branch_id' => $this->branch->id]);

    $this->employee = User::factory()->create([
        'name' => 'أحمد علي',
        'username' => 'ahmed',
        'branch_id' => $this->branch->id,
    ]);
    $this->employee->addRole(Roles::EMPLOYEE->value);

    $this->actingAs($this->admin);
});

describe('export', function () {
    it('writes the branch services sheet and the employee commissions sheet', function () {
        $service = attachedService($this->branch, 'طباعة ملونة', [
            'base_commission_pct' => 12.5,
            'max_discount_pct' => 20,
            'max_selling_price' => 90,
            'note_examples' => ['وجه واحد', 'وجهين'],
            'is_active' => true,
        ]);

        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $service->id,
            'commission_override_pct' => 7.25,
        ]);

        $sheets = (new BranchServicesExport($this->branch->id))->sheets();

        expect($sheets[0]->collection()->first())->toBe([
            'طباعة ملونة', '12.50', '20.00', '90.00', '', 'بالوحدة', '0.00', '0.00', 0, 0, '0.00', 'وجه واحد | وجهين', 1,
        ]);

        expect($sheets[1]->collection()->first())->toBe(['طباعة ملونة', 'أحمد علي', 'ahmed', '7.25']);
    });

    it('leaves another branch out of the sheet', function () {
        attachedService(Branch::factory()->create(), 'خدمة فرعٍ آخر');

        expect((new BranchServicesExport($this->branch->id))->sheets()[0]->collection())->toBeEmpty();
    });

    it('answers the export route with a downloadable sheet', function () {
        $this->get(route('branch-services.export'))
            ->assertOk()
            ->assertDownload();
    });

    it('keeps an employee away from the export', function () {
        $this->actingAs($this->employee)
            ->get(route('branch-services.export'))
            ->assertForbidden();
    });
});

describe('import', function () {
    it('attaches a general service template the sheet names', function () {
        $template = ServiceTemplate::factory()->create(['name' => 'تغليف حراري']);

        $response = $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet([serviceRow('تغليف حراري', [1 => '8.00', 2 => '3.00'])]),
        ])->assertOk();

        expect($response->json('totalRows'))->toBe(1);
        $this->assertDatabaseHas('branch_services', [
            'branch_id' => $this->branch->id,
            'service_template_id' => $template->id,
            'base_commission_pct' => 8.00,
            'max_discount_pct' => 3.00,
        ]);
    });

    it('creates a branch-owned template for a name nobody defined yet', function () {
        $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet([serviceRow('ليزر على الخشب')]),
        ])->assertOk();

        $this->assertDatabaseHas('service_templates', [
            'name' => 'ليزر على الخشب',
            'branch_id' => $this->branch->id,
        ]);
        expect(BranchService::where('branch_id', $this->branch->id)->count())->toBe(1);
    });

    it('never reaches a template another branch owns', function () {
        $other = Branch::factory()->create();
        ServiceTemplate::factory()->create(['name' => 'خدمة الجيران', 'branch_id' => $other->id]);

        $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet([serviceRow('خدمة الجيران')]),
        ])->assertOk();

        // قالبٌ جديد مملوك لفرعنا، والقالب المجاور لم يُمسّ ولم يُربط.
        expect(ServiceTemplate::where('name', 'خدمة الجيران')->count())->toBe(2);
        $this->assertDatabaseHas('service_templates', [
            'name' => 'خدمة الجيران',
            'branch_id' => $this->branch->id,
        ]);
    });

    it('updates the service of the same name instead of duplicating it', function () {
        $service = attachedService($this->branch, 'تجليد', [
            'base_commission_pct' => 5,
            'max_discount_pct' => 5,
        ]);

        $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet([serviceRow('تجليد', [1 => '15.00'])]),
        ])->assertOk();

        expect(BranchService::where('branch_id', $this->branch->id)->count())->toBe(1);
        expect((float) $service->fresh()->base_commission_pct)->toBe(15.0);
    });

    it('leaves a column the sheet omits untouched', function () {
        $service = attachedService($this->branch, 'تجليد', [
            'base_commission_pct' => 5,
            'max_discount_pct' => 9,
            'max_selling_price' => 120,
        ]);

        // ورقةٌ مقصوصة إلى عمودَين: الاسم والعمولة.
        $this->post(route('branch-services.import'), [
            'file' => branchServicesWorkbook([[
                'title' => BranchServicesExport::SERVICES_SHEET,
                'headings' => ['الخدمة', 'نسبة العمولة'],
                'rows' => [['تجليد', '30.00']],
            ]]),
        ])->assertOk();

        $service->refresh();
        expect((float) $service->base_commission_pct)->toBe(30.0)
            ->and((float) $service->max_discount_pct)->toBe(9.0)
            ->and((float) $service->max_selling_price)->toBe(120.0);
    });

    it('opens a price cap the sheet emptied on purpose', function () {
        $service = attachedService($this->branch, 'تجليد', ['max_selling_price' => 120]);

        $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet([serviceRow('تجليد')]),
        ])->assertOk();

        expect($service->fresh()->max_selling_price)->toBeNull();
    });

    it('reads a square-metre service with its metre price', function () {
        $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet([serviceRow('بنر', [5 => 'بالمتر المربع', 6 => '25.00'])]),
        ])->assertOk();

        $service = BranchService::where('branch_id', $this->branch->id)->firstOrFail();
        expect($service->pricing_type->value)->toBe('sqm')
            ->and((float) $service->price_per_sqm)->toBe(25.0);
    });

    it('refuses a square-metre service with no metre price', function () {
        $response = $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet([serviceRow('بنر', [5 => 'بالمتر المربع', 6 => '0.00'])]),
        ])->assertOk();

        expect($response->json('skipped.0.reason'))->toContain('سعر وحدة القياس');
        expect(BranchService::where('branch_id', $this->branch->id)->count())->toBe(0);
    });

    // تاسك 80: وحدة القياس الثالثة تدخل بنفس الورقة وبنفس العمود — «سعر المتر»
    // يُقرأ سعر وحدة القياس أيّاً كانت، فلا عمود جديد ولا ورقة جديدة.
    it('reads a linear-metre service with its metre price', function () {
        $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet([serviceRow('شريط', [5 => 'بالمتر الطولي', 6 => '5.00'])]),
        ])->assertOk();

        $service = BranchService::where('branch_id', $this->branch->id)->firstOrFail();
        expect($service->pricing_type->value)->toBe('linear')
            ->and((float) $service->price_per_sqm)->toBe(5.0);
    });

    it('refuses a linear-metre service with no metre price', function () {
        $response = $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet([serviceRow('شريط', [5 => 'بالمتر الطولي', 6 => '0.00'])]),
        ])->assertOk();

        expect($response->json('skipped.0.reason'))->toContain('سعر وحدة القياس');
        expect(BranchService::where('branch_id', $this->branch->id)->count())->toBe(0);
    });

    it('reports a row whose commission is out of range instead of writing it', function () {
        $response = $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet([serviceRow('تجليد', [1 => '140.00'])]),
        ])->assertOk();

        expect($response->json('skipped'))->toHaveCount(1)
            ->and($response->json('skipped.0.reason'))->toContain('بين 0 و100');
        expect(ServiceTemplate::where('name', 'تجليد')->count())->toBe(0);
    });

    it('refuses a floor above the cap', function () {
        $response = $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet([serviceRow('تجليد', [3 => '50.00', 4 => '80.00'])]),
        ])->assertOk();

        expect($response->json('skipped.0.reason'))->toContain('أقل سعر للبيع');
    });

    it('splits the note examples cell on its separator', function () {
        $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet([serviceRow('تجليد', [11 => 'وجه واحد | وجهين | وجه واحد'])]),
        ])->assertOk();

        expect(BranchService::where('branch_id', $this->branch->id)->firstOrFail()->note_examples)
            ->toBe(['وجه واحد', 'وجهين']);
    });
});

describe('employee commissions sheet', function () {
    it('sets a rate for the employee the second sheet names', function () {
        $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet(
                [serviceRow('تجليد')],
                [['تجليد', 'أحمد علي', 'ahmed', '9.50']],
            ),
        ])->assertOk();

        $service = BranchService::where('branch_id', $this->branch->id)->firstOrFail();
        $this->assertDatabaseHas('user_services', [
            'user_id' => $this->employee->id,
            'branch_service_id' => $service->id,
            'commission_override_pct' => 9.50,
        ]);
    });

    it('clears a rate the sheet emptied — the zero fallback', function () {
        $service = attachedService($this->branch, 'تجليد');
        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $service->id,
            'commission_override_pct' => 4,
        ]);

        $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet(
                [serviceRow('تجليد')],
                [['تجليد', 'أحمد علي', 'ahmed', '']],
            ),
        ])->assertOk();

        $this->assertDatabaseMissing('user_services', [
            'user_id' => $this->employee->id,
            'branch_service_id' => $service->id,
        ]);
    });

    it('reports an employee who is not in this branch', function () {
        $stranger = User::factory()->create(['name' => 'غريب', 'username' => 'stranger']);
        $stranger->addRole(Roles::EMPLOYEE->value);

        $response = $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet(
                [serviceRow('تجليد')],
                [['تجليد', 'غريب', 'stranger', '9.00']],
            ),
        ])->assertOk();

        expect($response->json('skipped.0.reason'))->toContain('موظف غير معروف');
        expect(UserService::count())->toBe(0);
    });

    it('reports a service the branch does not carry', function () {
        $response = $this->post(route('branch-services.import'), [
            'file' => branchServicesSheet(
                [],
                [['خدمة لا وجود لها', 'أحمد علي', 'ahmed', '9.00']],
            ),
        ])->assertOk();

        expect($response->json('skipped.0.reason'))->toContain('غير مرتبطة بهذا الفرع');
    });
});

describe('preview', function () {
    it('describes the file without writing a single row', function () {
        $response = $this->post(route('branch-services.import.preview'), [
            'file' => branchServicesSheet([serviceRow('تجليد')]),
        ])->assertOk();

        expect($response->json('dryRun'))->toBeTrue()
            ->and($response->json('totalRows'))->toBe(1)
            ->and($response->json('token'))->not->toBeNull();

        expect(BranchService::count())->toBe(0);
        expect(ServiceTemplate::where('name', 'تجليد')->count())->toBe(0);
    });

    it('commits the very file the preview parked', function () {
        $token = $this->post(route('branch-services.import.preview'), [
            'file' => branchServicesSheet([serviceRow('تجليد')]),
        ])->json('token');

        $this->post(route('branch-services.import'), ['token' => $token])->assertOk();

        expect(BranchService::where('branch_id', $this->branch->id)->count())->toBe(1);
    });

    it('keeps an employee away from the import', function () {
        $this->actingAs($this->employee)
            ->post(route('branch-services.import.preview'), [
                'file' => branchServicesSheet([serviceRow('تجليد')]),
            ])
            ->assertForbidden();
    });

    it('serves a two-sheet template', function () {
        $this->get(route('branch-services.import.template'))
            ->assertOk()
            ->assertDownload('branch-services-template.xlsx');
    });
});
