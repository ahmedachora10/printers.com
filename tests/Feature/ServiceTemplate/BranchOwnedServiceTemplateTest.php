<?php

use App\Models\Branch;
use App\Models\City;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * تاسك 45: مدير الفرع يضيف خدمة جديدة دون الرجوع للأدمن. القالب الذي ينشئه
 * مملوك لفرعه (`branch_id`)، ولا يظهر إلا عنده وعند السوبر أدمن؛ وما ينشئه
 * السوبر أدمن يبقى عاماً لكل الفروع (`branch_id = null`).
 */
describe('Branch-owned service templates', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $city = City::factory()->create();

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole('branch-admin');

        $this->branch = Branch::factory()->create([
            'city_id' => $city->id,
            'owner_id' => $this->branchAdmin->id,
        ]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->otherAdmin = User::factory()->create();
        $this->otherAdmin->addRole('branch-admin');

        $this->otherBranch = Branch::factory()->create([
            'city_id' => $city->id,
            'owner_id' => $this->otherAdmin->id,
        ]);
        $this->otherAdmin->update(['branch_id' => $this->otherBranch->id]);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole('super-admin');
    });

    it('lets a branch-admin create a service owned by their own branch', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('service-templates.store'), [
                'name' => 'طباعة استيكرات',
                'is_active' => true,
            ])
            ->assertRedirect(route('branch-services.index'));

        $this->assertDatabaseHas('service_templates', [
            'name' => 'طباعة استيكرات',
            'branch_id' => $this->branch->id,
        ]);
    });

    it('keeps a super-admin service global', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('service-templates.store'), [
                'name' => 'تصوير مستندات',
                'is_active' => true,
            ])
            ->assertRedirect(route('service-templates.index'));

        $this->assertDatabaseHas('service_templates', [
            'name' => 'تصوير مستندات',
            'branch_id' => null,
        ]);
    });

    it('forbids an employee from creating a service', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole('employee');

        $this->actingAs($employee)
            ->post(route('service-templates.store'), ['name' => 'خدمة', 'is_active' => true])
            ->assertForbidden();

        expect(ServiceTemplate::count())->toBe(0);
    });

    it('offers a branch its own services and the global ones, never another branch\'s', function () {
        $global = ServiceTemplate::factory()->create(['name' => 'خدمة عامة', 'is_active' => true]);
        $mine = ServiceTemplate::factory()->create([
            'name' => 'خدمة فرعي', 'is_active' => true, 'branch_id' => $this->branch->id,
        ]);
        $theirs = ServiceTemplate::factory()->create([
            'name' => 'خدمة فرع آخر', 'is_active' => true, 'branch_id' => $this->otherBranch->id,
        ]);

        $this->actingAs($this->branchAdmin)
            ->get(route('branch-services.index'))
            ->assertInertia(function ($page) use ($global, $mine, $theirs) {
                $ids = collect($page->toArray()['props']['serviceTemplates'])->pluck('id')->all();

                expect($ids)->toContain($global->id)
                    ->toContain($mine->id)
                    ->not->toContain($theirs->id);
            });
    });

    it('refuses to link another branch\'s service to this branch', function () {
        $theirs = ServiceTemplate::factory()->create([
            'name' => 'خدمة فرع آخر', 'is_active' => true, 'branch_id' => $this->otherBranch->id,
        ]);

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $theirs->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 0,
            ])
            ->assertSessionHasErrors('service_template_id');

        $this->assertDatabaseCount('branch_services', 0);
    });

    it('lets a branch-admin edit and delete their own service but not a global one', function () {
        $mine = ServiceTemplate::factory()->create(['branch_id' => $this->branch->id]);
        $global = ServiceTemplate::factory()->create(['branch_id' => null]);

        expect($this->branchAdmin->can('update', $mine))->toBeTrue()
            ->and($this->branchAdmin->can('delete', $mine))->toBeTrue()
            ->and($this->branchAdmin->can('update', $global))->toBeFalse()
            ->and($this->otherAdmin->can('update', $mine))->toBeFalse();
    });
});
