<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** خدمةُ فرعٍ من قالبٍ بعينه — الـPivot لا يعيد المفتاح عند الإنشاء فتُعاد قراءتها. */
function attachTemplate(Branch $branch, ServiceTemplate $template, bool $active = true): BranchService
{
    BranchService::create([
        'branch_id' => $branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 0,
        'is_active' => $active,
    ]);

    return BranchService::where('branch_id', $branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

/** موظف بائع في فرع، مربوطٌ بخدمةٍ بنسبة عمولة. */
function seller(Branch $branch): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->addRole(Roles::EMPLOYEE->value);

    return $user;
}

/**
 * تاسك 116: تعطيل القالب من مدير النظام = تعطيلٌ شاملٌ على كل الفروع، وحالةُ كل
 * فرعٍ باقيةٌ تحته — فإعادة التفعيل تُرجع كل فرعٍ إلى ما كان عليه بلا جدول
 * «حالة سابقة».
 */
describe('Service template disable cascades to every branch', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->template = ServiceTemplate::factory()->create(['is_active' => true]);

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        $this->serviceA = attachTemplate($this->branchA, $this->template);
        $this->serviceB = attachTemplate($this->branchB, $this->template);

        $this->sellerA = seller($this->branchA);
        $this->sellerB = seller($this->branchB);
    });

    it('hides the service from every branch POS once the template is switched off', function () {
        foreach ([[$this->sellerA], [$this->sellerB]] as [$user]) {
            $this->actingAs($user)
                ->get(route('pos.service.create'))
                ->assertInertia(fn ($page) => $page->has('services', 1));
        }

        $this->template->update(['is_active' => false]);

        foreach ([[$this->sellerA], [$this->sellerB]] as [$user]) {
            $this->actingAs($user)
                ->get(route('pos.service.create'))
                ->assertInertia(fn ($page) => $page->has('services', 0));
        }
    });

    it('restores each branch to the state it was already in', function () {
        // الفرع B عطّله مديره قبل تعطيل القالب.
        $this->serviceB->update(['is_active' => false]);

        $this->template->update(['is_active' => false]);
        $this->template->update(['is_active' => true]);

        $this->actingAs($this->sellerA)
            ->get(route('pos.service.create'))
            ->assertInertia(fn ($page) => $page->has('services', 1));

        // ولم تُدهس حالته: يبقى معطَّلاً بعد عودة القالب.
        $this->actingAs($this->sellerB)
            ->get(route('pos.service.create'))
            ->assertInertia(fn ($page) => $page->has('services', 0));

        expect($this->serviceB->fresh()->is_active)->toBeFalse()
            ->and($this->serviceA->fresh()->is_active)->toBeTrue();
    });

    it('refuses to sell a service whose template the admin switched off', function () {
        $this->template->update(['is_active' => false]);

        $this->actingAs($this->sellerA)
            ->post(route('pos.service.store'), [
                'status' => 'due',
                'lines' => [[
                    'branch_service_id' => $this->serviceA->id,
                    'qty' => 1,
                    'unit_price' => 100,
                    'discount_pct' => 0,
                ]],
            ])
            ->assertSessionHasErrors('lines');
    });

    it('tells the branch admin why the row is off, and refuses to switch it on', function () {
        $admin = User::factory()->create();
        $admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branchA->update(['owner_id' => $admin->id]);
        $admin->update(['branch_id' => $this->branchA->id]);

        $this->template->update(['is_active' => false]);

        $this->actingAs($admin)
            ->get(route('branch-services.index'))
            ->assertInertia(fn ($page) => $page
                ->where('branchServices.data.0.isActive', true)
                ->where('branchServices.data.0.templateIsActive', false));

        $this->actingAs($admin)
            ->put(route('branch-services.update', $this->serviceA), [
                'base_commission_pct' => 10,
                'max_discount_pct' => 0,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('is_active');
    });
});
