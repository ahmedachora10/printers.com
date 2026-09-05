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

/** خدمة فرعٍ بشروط خاماتٍ مُعطاة، وللموظف عليها نسبة 50%. */
function openMaterialsService(array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create(array_merge([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 50,
        'max_discount_pct' => 50,
        'is_tahazir' => false,
        'has_materials' => false,
        'materials_cost' => 0,
        'is_active' => true,
    ], $attrs));

    $service = BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();

    UserService::create([
        'user_id' => test()->employee->id,
        'branch_service_id' => $service->id,
        'commission_override_pct' => 50,
    ]);

    return $service;
}

/** فاتورة معلّقة من سطرٍ واحد على الخدمة المُعطاة. */
function openMaterialsPayload(BranchService $service, array $lineAttrs = []): array
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

/**
 * تاسك 77: خدمةٌ «لها خامات» وتكلفتها صفر = التكلفة تُحدَّد وقت البيع، فيكتبها
 * الموظف في الفاتورة. وما عدا ذلك يبقى منع تاسك 54 على حاله حرفياً.
 */
describe('Employee-authored materials cost', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($this->employee);
    });

    it('keeps what the employee typed on a zero-cost service', function () {
        $service = openMaterialsService(['has_materials' => true, 'materials_cost' => 0]);

        $this->post(route('pos.service.store'), openMaterialsPayload($service, [
            'unit_price' => 200,
            'materials_cost' => 45,
        ]))->assertRedirect();

        $line = ServiceInvoice::firstOrFail()->lines()->firstOrFail();

        // 200 ÷ 1.15 = 173.91 → − 45 خامات = 128.91 → × 50% = 64.46
        expect((float) $line->materials_cost)->toEqual(45.00)
            ->and((float) $line->materials_total)->toEqual(45.00)
            ->and((float) $line->commission_amount)->toEqual(64.46);
    });

    it('still ignores what the employee sends when the service prices its own materials', function () {
        $service = openMaterialsService(['has_materials' => true, 'materials_cost' => 20]);

        $this->post(route('pos.service.store'), openMaterialsPayload($service, [
            'unit_price' => 200,
            'materials_cost' => 45,
        ]))->assertRedirect();

        // تاسك 54 لم ينكسر: الرقم من تعريف الخدمة والمرسَل مُهمَل.
        expect((float) ServiceInvoice::firstOrFail()->lines()->firstOrFail()->materials_cost)->toEqual(20.00);
    });

    it('keeps a service with no materials at zero however much is sent', function () {
        $service = openMaterialsService(['has_materials' => false, 'materials_cost' => 0]);

        $this->post(route('pos.service.store'), openMaterialsPayload($service, [
            'materials_cost' => 45,
        ]))->assertRedirect();

        expect((float) ServiceInvoice::firstOrFail()->lines()->firstOrFail()->materials_cost)->toEqual(0.00);
    });

    it('demands a positive figure on an open service', function () {
        $service = openMaterialsService(['has_materials' => true, 'materials_cost' => 0]);

        // اختيارية الرقم تُسقط أرضية السعر وتكبّر العمولة معاً، فهو مُلزِم موجب.
        $this->post(route('pos.service.store'), openMaterialsPayload($service))
            ->assertSessionHasErrors('lines.0.materials_cost');

        $this->post(route('pos.service.store'), openMaterialsPayload($service, ['materials_cost' => 0]))
            ->assertSessionHasErrors('lines.0.materials_cost');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('lets the price floor follow the typed figure', function () {
        $service = openMaterialsService(['has_materials' => true, 'materials_cost' => 0]);

        // أرضية السعر = تكلفة الخامة شاملةً الضريبة: 100 × 1.15 = 115.00،
        // فبيعٌ بـ110 يُرفض بالرقم الذي كتبه الموظف نفسه.
        $this->post(route('pos.service.store'), openMaterialsPayload($service, [
            'unit_price' => 110,
            'materials_cost' => 100,
        ]))->assertSessionHasErrors('lines');

        // وبتكلفة 50 تنزل الأرضية إلى 57.50 فيمرّ السعر نفسه.
        $this->post(route('pos.service.store'), openMaterialsPayload($service, [
            'unit_price' => 110,
            'materials_cost' => 50,
        ]))->assertRedirect();

        expect((float) ServiceInvoice::firstOrFail()->lines()->firstOrFail()->materials_cost)->toEqual(50.00);
    });

    it('tells the POS which services are open', function () {
        $open = openMaterialsService(['has_materials' => true, 'materials_cost' => 0]);
        openMaterialsService(['has_materials' => true, 'materials_cost' => 20]);

        $this->get(route('pos.service.create'))
            ->assertOk()
            ->assertInertia(function ($page) use ($open) {
                $services = collect($page->toArray()['props']['services']);

                expect($services->firstWhere('id', $open->id)['materialsCostIsOpen'])->toBeTrue()
                    ->and($services->where('materialsCostIsOpen', false))->toHaveCount(1);
            });
    });

    it('records in the activity log who authored the figure', function () {
        $service = openMaterialsService(['has_materials' => true, 'materials_cost' => 0]);

        $this->post(route('pos.service.store'), openMaterialsPayload($service, [
            'unit_price' => 200,
            'materials_cost' => 45,
        ]))->assertRedirect();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'invoices',
            'causer_id' => $this->employee->id,
            'subject_type' => ServiceInvoice::class,
        ]);
    });

    it('leaves a manager free to price the line at zero', function () {
        $service = openMaterialsService(['has_materials' => true, 'materials_cost' => 0]);

        $branchAdmin = User::factory()->create();
        $branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $branchAdmin->id]);

        // من يملك تعديل الخامات ليس عليه المنع أصلاً، فلا يلزمه رقم موجب.
        $this->actingAs($branchAdmin)
            ->post(route('pos.service.store'), openMaterialsPayload($service, ['has_materials' => false]))
            ->assertRedirect();

        expect((float) ServiceInvoice::firstOrFail()->lines()->firstOrFail()->materials_cost)->toEqual(0.00);
    });
});
