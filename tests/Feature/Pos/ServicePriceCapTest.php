<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * سقف سعر البيع (أعلى سعر للبيع): خانة اختيارية على خدمة الفرع تمنع الموظف من
 * البيع بأعلى منها، وتترك السعر مفتوحاً حين تُترك فارغة.
 */
function cappedService(array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create(array_merge([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'max_selling_price' => 150,
        'is_tahazir' => false,
        'is_active' => true,
    ], $attrs));

    // BranchService is a Pivot (non-incrementing) — re-fetch for the id.
    return BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

/** A single-line DUE payload over the given service. */
function capPayload(BranchService $service, array $lineAttrs = []): array
{
    return [
        'payment_method_id' => paymentMethodId(),
        'status' => 'due',
        'lines' => [array_merge([
            'branch_service_id' => $service->id,
            'qty' => 1,
            'unit_price' => 100,
            'discount_pct' => 0,
        ], $lineAttrs)],
    ];
}

describe('Max selling price cap', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        // Branch admins are linked through branches.owner_id, not users.branch_id.
        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->actingAs($this->employee);
    });

    // ── الخدمات المسعّرة بالوحدة ────────────────────────────────────

    it('blocks an employee selling above the cap', function () {
        $service = cappedService();

        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 151]))
            ->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('allows an employee selling exactly at the cap', function () {
        $service = cappedService();

        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 150]))
            ->assertSessionHasNoErrors();

        expect((float) ServiceInvoice::firstOrFail()->lines->firstOrFail()->unit_price)->toBe(150.00);
    });

    it('leaves the price open when no cap is set', function () {
        $service = cappedService(['max_selling_price' => null]);

        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 9999]))
            ->assertSessionHasNoErrors();

        expect((float) ServiceInvoice::firstOrFail()->lines->firstOrFail()->unit_price)->toBe(9999.00);
    });

    it('reads a zero cap as open, not as a demand for free work', function () {
        $service = cappedService(['max_selling_price' => 0]);

        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 500]))
            ->assertSessionHasNoErrors();

        expect(ServiceInvoice::count())->toBe(1);
    });

    it('does not bind a branch admin', function () {
        $service = cappedService();

        $this->actingAs($this->branchAdmin)
            ->post(route('pos.service.store'), capPayload($service, ['unit_price' => 400]))
            ->assertSessionHasNoErrors();

        expect((float) ServiceInvoice::firstOrFail()->lines->firstOrFail()->unit_price)->toBe(400.00);
    });

    it('caps the line before any discount, so a discount cannot buy headroom', function () {
        $service = cappedService();

        // 200 مخصومةً 20% تنزل إلى 160، لكن السعر المكتوب هو ما يُقاس بالسقف.
        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 200, 'discount_pct' => 20]))
            ->assertSessionHasErrors('lines');
    });

    // ── الخدمات المسعّرة بالمتر المربع ──────────────────────────────

    it('caps the meter price, not the total of the line', function () {
        $service = cappedService([
            'pricing_type' => 'sqm',
            'price_per_sqm' => 100,
            'max_selling_price' => 150,
        ]);

        // 2م × 1م = 2 م² بسعر متر 150 — عند السقف تماماً فيمرّ، مع أن إجمالي
        // السطر (300.00) ضعف السقف: السقف على المتر لا على المجموع.
        $this->post(route('pos.service.store'), capPayload($service, [
            'payment_method_id' => paymentMethodId(),
            'unit_price' => 150,
            'width_cm' => 200,
            'height_cm' => 100,
        ]))->assertSessionHasNoErrors();

        $line = ServiceInvoice::firstOrFail()->lines->firstOrFail();

        expect((float) $line->unit_price)->toBe(150.00)
            ->and((float) $line->subtotal)->toBe(300.00);
    });

    it('blocks a sqm line whose per-meter price exceeds the cap', function () {
        $service = cappedService([
            'pricing_type' => 'sqm',
            'price_per_sqm' => 100,
            'max_selling_price' => 150,
        ]);

        // 160 للمتر > 150.
        $this->post(route('pos.service.store'), capPayload($service, [
            'payment_method_id' => paymentMethodId(),
            'unit_price' => 160,
            'width_cm' => 200,
            'height_cm' => 100,
        ]))->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    // ── تعريف الخدمة ────────────────────────────────────────────────

    it('persists the cap from the branch service form and clears it when emptied', function () {
        $template = ServiceTemplate::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'max_selling_price' => 250.50,
            ])->assertSessionHasNoErrors();

        $service = BranchService::where('service_template_id', $template->id)->firstOrFail();
        expect((float) $service->max_selling_price)->toBe(250.50);

        $this->actingAs($this->branchAdmin)
            ->put(route('branch-services.update', $service->id), [
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'max_selling_price' => '',
            ])->assertSessionHasNoErrors();

        expect($service->fresh()->max_selling_price)->toBeNull();
    });

    it('rejects a negative cap', function () {
        $template = ServiceTemplate::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'max_selling_price' => -1,
            ])->assertSessionHasErrors('max_selling_price');
    });

    // ── تاسك 64: أرضية السعر على تعريف الخدمة ───────────────────────
    // الإلزام في نقطة البيع يخصّ التاسك 65؛ هنا الخانة وتحقّقها وحدهما.

    it('persists the floor from the branch service form and clears it when emptied', function () {
        $template = ServiceTemplate::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'min_selling_price' => 50.25,
            ])->assertSessionHasNoErrors();

        $service = BranchService::where('service_template_id', $template->id)->firstOrFail();
        expect((float) $service->min_selling_price)->toBe(50.25);

        $this->actingAs($this->branchAdmin)
            ->put(route('branch-services.update', $service->id), [
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'min_selling_price' => '',
            ])->assertSessionHasNoErrors();

        expect($service->fresh()->min_selling_price)->toBeNull();
    });

    it('rejects a floor above the cap — the service would be unsellable', function () {
        $template = ServiceTemplate::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'max_selling_price' => 100,
                'min_selling_price' => 150,
            ])->assertSessionHasErrors('min_selling_price');

        expect(BranchService::where('service_template_id', $template->id)->exists())->toBeFalse();
    });

    it('accepts a floor equal to the cap — one price exactly', function () {
        $template = ServiceTemplate::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'max_selling_price' => 100,
                'min_selling_price' => 100,
            ])->assertSessionHasNoErrors();
    });

    it('measures the floor against nothing when the cap is left open', function () {
        $template = ServiceTemplate::factory()->create();

        // بلا سقف لا معنى لمقارنة الأرضية به — ولا يُقرأ فراغه صفراً.
        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'max_selling_price' => '',
                'min_selling_price' => 900,
            ])->assertSessionHasNoErrors();

        expect((float) BranchService::where('service_template_id', $template->id)->firstOrFail()->min_selling_price)->toBe(900.00);
    });

    it('rejects a negative floor', function () {
        $template = ServiceTemplate::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'min_selling_price' => -1,
            ])->assertSessionHasErrors('min_selling_price');
    });

    it('ships the floor to the POS beside the cap', function () {
        $service = cappedService(['min_selling_price' => 40]);

        $this->get(route('pos.service.create'))->assertInertia(
            fn ($page) => $page->where(
                'services',
                fn ($services) => collect($services)->firstWhere('id', $service->id)['minSellingPrice'] === 40,
            ),
        );
    });
});

/**
 * تاسك 65: أرضية السعر — أعلى الحدَّين، أقل سعر معرَّف على الخدمة وتكلفة خامات
 * السطر. تُقاس على المقبوض: بعد خصم السطر، **وشاملةً الضريبة** (15% في هذا
 * الفرع) لأن السعر المكتوب شاملٌ لها. فـ`min_selling_price` سعرٌ يُقارَن كما هو،
 * وتكلفة الخامة مبلغٌ صافٍ يُرفع بالضريبة قبل المقارنة وحدها.
 */
describe('Minimum selling price floor', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->actingAs($this->employee);
    });

    // ── أرضية أقل سعر (تاسك 64) ─────────────────────────────────────

    it('blocks an employee selling below the configured floor', function () {
        $service = cappedService(['max_selling_price' => null, 'min_selling_price' => 50]);

        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 49.99]))
            ->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('reads the configured floor as a VAT-inclusive price, like every other price', function () {
        $service = cappedService(['max_selling_price' => null, 'min_selling_price' => 50]);

        // أقل سعر 50 يعني «اكتب 50» — لا 57.50. الرقم سعرٌ يكتبه المدير،
        // والأسعار شاملة الضريبة منذ التاسك 37، فلا يُقسم عليها مرّتين.
        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 50]))
            ->assertSessionHasNoErrors();

        expect(ServiceInvoice::count())->toBe(1);
    });

    it('leaves the price open downwards when no floor is set', function () {
        $service = cappedService(['max_selling_price' => null, 'min_selling_price' => null]);

        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 1]))
            ->assertSessionHasNoErrors();

        expect(ServiceInvoice::count())->toBe(1);
    });

    it('does not bind a branch admin', function () {
        $service = cappedService(['max_selling_price' => null, 'min_selling_price' => 50]);

        $this->actingAs($this->branchAdmin)
            ->post(route('pos.service.store'), capPayload($service, ['unit_price' => 10]))
            ->assertSessionHasNoErrors();

        expect(ServiceInvoice::count())->toBe(1);
    });

    // ── أرضية تكلفة الخامة (نصّ العميل) ─────────────────────────────

    it('blocks a sale below the line materials cost', function () {
        $service = cappedService([
            'max_selling_price' => null,
            'has_materials' => true,
            'materials_cost' => 60,
        ]);

        // خامة 60 صافية = أرضية 69.00 شاملة، و57.50 دونها — بيعٌ بخسارة.
        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 57.50]))
            ->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('rejects a price that only covers the cost before VAT is added', function () {
        $service = cappedService([
            'max_selling_price' => null,
            'has_materials' => true,
            'materials_cost' => 50,
        ]);

        // 50 شاملة الضريبة تبدو مساويةً للتكلفة، والمقبوض 43.48 — خسارة 6.52.
        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 50]))
            ->assertSessionHasErrors('lines');
    });

    it('names the materials floor with its VAT-inclusive figure and its net origin', function () {
        // حالة العميل نفسها: خامة 20 صافية، فالأرضية 23.00 شاملة والـ22 دونها.
        $service = cappedService([
            'max_selling_price' => null,
            'has_materials' => true,
            'materials_cost' => 20,
        ]);

        $response = $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 22]));

        $response->assertSessionHasErrors('lines');
        // الرسالة تحمل الرقم الذي يكتبه وأصلَه الذي يراه في شاشة الخدمة.
        expect(session('errors')->first('lines'))
            ->toContain('23.00')
            ->toContain('20.00');

        // و23.00 بالضبط تمرّ — هي «اكتب هذا فأكثر».
        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 23]))
            ->assertSessionHasNoErrors();

        expect(ServiceInvoice::count())->toBe(1);
    });

    it('leaves the commission base untouched — the gross-up is the floor check alone', function () {
        $service = cappedService([
            'max_selling_price' => null,
            'has_materials' => true,
            'materials_cost' => 20,
        ]);

        // نسبة الموظف تأتي من user_services وحدها (الافتراضي صفر).
        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $service->id,
            'commission_override_pct' => 10,
        ]);

        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 115]))
            ->assertSessionHasNoErrors();

        // 115 شاملة = 100 صافية، ناقص التكلفة الصافية 20 = 80 × 10% = 8.00.
        // لو قُسمت التكلفة بالضريبة لصارت 8.17 — والعمولات لا تتحرّك بهذا التاسك.
        expect((float) ServiceInvoice::firstOrFail()->employee_commission)->toBe(8.00);
    });

    it('measures the floor after the line discount, unlike the cap', function () {
        $service = cappedService([
            'max_selling_price' => null,
            'max_discount_pct' => 60,
            'has_materials' => true,
            'materials_cost' => 50,
        ]);

        // 115 مكتوبةً فوق أرضية الخامة (57.50 شاملة) بمريح، وخصم 50% ينزل بها
        // إلى 57.50 — عند الأرضية تماماً فتمرّ.
        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 115, 'discount_pct' => 50]))
            ->assertSessionHasNoErrors();

        // وخصمٌ أكبر بقليل ينزل بها تحت التكلفة فتُرفض.
        ServiceInvoice::query()->forceDelete();
        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 115, 'discount_pct' => 60]))
            ->assertSessionHasErrors('lines');
    });

    it('compares metre against metre on a per-sqm service', function () {
        $service = cappedService([
            'max_selling_price' => null,
            'pricing_type' => 'sqm',
            'price_per_sqm' => 100,
            'has_materials' => true,
            'materials_cost' => 10,
        ]);

        // خامة المتر 10 صافية = أرضية 11.50 شاملة للمتر — تمرّ عندها تماماً
        // مهما كبر المقاس: الطرفان كلاهما «للمتر» بعد التاسك 63.
        $this->post(route('pos.service.store'), capPayload($service, [
            'payment_method_id' => paymentMethodId(),
            'unit_price' => 11.50,
            'width_cm' => 300,
            'height_cm' => 200,
        ]))->assertSessionHasNoErrors();

        ServiceInvoice::query()->forceDelete();
        $this->post(route('pos.service.store'), capPayload($service, [
            'payment_method_id' => paymentMethodId(),
            'unit_price' => 11,
            'width_cm' => 300,
            'height_cm' => 200,
        ]))->assertSessionHasErrors('lines');
    });

    it('ignores the materials cost when the service carries none', function () {
        $service = cappedService(['max_selling_price' => null, 'has_materials' => false, 'materials_cost' => 500]);

        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 5]))
            ->assertSessionHasNoErrors();

        expect(ServiceInvoice::count())->toBe(1);
    });

    // ── الحدّان معاً ────────────────────────────────────────────────

    it('takes the higher of the two floors and names it', function () {
        $service = cappedService([
            'max_selling_price' => null,
            'min_selling_price' => 20,
            'has_materials' => true,
            'materials_cost' => 80,
        ]);

        // فوق أقل سعر (20) لكن تحت أرضية الخامة (80 صافية = 92.00 شاملة) —
        // تُرفض، والرسالة تسمّيها.
        $response = $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 57.50]));

        $response->assertSessionHasErrors('lines');
        expect(session('errors')->first('lines'))->toContain('تكلفة الخامات');
    });

    it('names the configured floor when it is the higher of the two', function () {
        $service = cappedService([
            'max_selling_price' => null,
            'min_selling_price' => 80,
            'has_materials' => true,
            'materials_cost' => 20,
        ]);

        $response = $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 57.50]));

        $response->assertSessionHasErrors('lines');
        expect(session('errors')->first('lines'))->toContain('أقل سعر للبيع');
    });
});
