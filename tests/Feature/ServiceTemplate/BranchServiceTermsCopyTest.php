<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * تاسك 79: نسخ شروط الربط من فرعٍ مربوط. النسخ نفسه عملٌ في الواجهة، فما
 * يُختبر هنا هو شرطه: أن تصل **كل** الشروط مع كل رابط في `template.branches`
 * — فما لا يصل لا يُنسخ، وأرضية السعر كانت ساقطة من أعمدة الـpivot.
 */
describe('Copying branch-service terms', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);
        $this->actingAs($this->superAdmin);
    });

    it('hands the manage modal every term it copies', function () {
        $template = ServiceTemplate::factory()->create(['name' => 'طباعة يوفي UV']);
        $branch = Branch::factory()->create();

        BranchService::create([
            'branch_id' => $branch->id,
            'service_template_id' => $template->id,
            'base_commission_pct' => 12.5,
            'max_discount_pct' => 5,
            'max_selling_price' => 300,
            'min_selling_price' => 180,
            'pricing_type' => 'sqm',
            'price_per_sqm' => 215,
            'agent_commission_per_sqm' => 7,
            'note_examples' => ['لامع', 'مطفي'],
            'is_tahazir' => true,
            'has_materials' => true,
            'materials_cost' => 45,
            'is_active' => true,
        ]);

        $this->get(route('service-templates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // الأرقام الصحيحة تصل بلا كسر عبر Inertia، فتُقارن كذلك.
                ->where('templates.data.0.branches.0.branchId', $branch->id)
                ->where('templates.data.0.branches.0.baseCommissionPct', 12.5)
                ->where('templates.data.0.branches.0.maxDiscountPct', 5)
                ->where('templates.data.0.branches.0.maxSellingPrice', 300)
                // أرضية السعر: كانت ساقطة من withPivot فتصل null دائماً.
                ->where('templates.data.0.branches.0.minSellingPrice', 180)
                ->where('templates.data.0.branches.0.pricingType', 'sqm')
                ->where('templates.data.0.branches.0.pricePerSqm', 215)
                ->where('templates.data.0.branches.0.agentCommissionPerSqm', 7)
                ->where('templates.data.0.branches.0.noteExamples', ['لامع', 'مطفي'])
                ->where('templates.data.0.branches.0.isTahazir', true)
                ->where('templates.data.0.branches.0.hasMaterials', true)
                ->where('templates.data.0.branches.0.materialsCost', 45));
    });

    it('attaches a second branch on the copied terms without touching the first', function () {
        $template = ServiceTemplate::factory()->create();
        $first = Branch::factory()->create();
        $second = Branch::factory()->create();

        BranchService::create([
            'branch_id' => $first->id,
            'service_template_id' => $template->id,
            'base_commission_pct' => 12.5,
            'max_discount_pct' => 5,
            'min_selling_price' => 180,
            'pricing_type' => 'sqm',
            'price_per_sqm' => 215,
            'is_tahazir' => true,
            'has_materials' => true,
            'materials_cost' => 45,
            'is_active' => true,
        ]);

        // ما ترسله الواجهة بعد النسخ، وقد عدّل المستخدم سعر المتر قبل الحفظ.
        $this->post(route('branch-services.store'), [
            'service_template_id' => $template->id,
            'branch_id' => $second->id,
            'base_commission_pct' => 12.5,
            'max_discount_pct' => 5,
            'min_selling_price' => 180,
            'pricing_type' => 'sqm',
            'price_per_sqm' => 230,
            'is_tahazir' => true,
            'has_materials' => true,
            'materials_cost' => 45,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('branch_services', [
            'branch_id' => $second->id,
            'service_template_id' => $template->id,
            'price_per_sqm' => 230,
            'materials_cost' => 45,
        ]);

        // تعديل خانة قبل الحفظ لا يمسّ الفرع الأول.
        $this->assertDatabaseHas('branch_services', [
            'branch_id' => $first->id,
            'service_template_id' => $template->id,
            'price_per_sqm' => 215,
        ]);
    });
});
