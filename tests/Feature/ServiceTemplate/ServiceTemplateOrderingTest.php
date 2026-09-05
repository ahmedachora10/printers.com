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
 * تاسك 82: ترتيب يدوي لقوالب الخدمات — والمقصد أن يسري حيث تُقرأ الخدمات
 * فعلاً، فترتيبٌ في شاشة الإدارة وحدها يرتّب شاشةً لا يراها البائع.
 */
describe('Service template ordering', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->alef = ServiceTemplate::factory()->create(['name' => 'أ خدمة', 'sort_order' => 1]);
        $this->baa = ServiceTemplate::factory()->create(['name' => 'ب خدمة', 'sort_order' => 2]);
        $this->jeem = ServiceTemplate::factory()->create(['name' => 'ج خدمة', 'sort_order' => 3]);
    });

    it('writes the sort order in the order sent', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('service-templates.reorder'), [
                'ids' => [$this->jeem->id, $this->alef->id, $this->baa->id],
            ])
            ->assertRedirect();

        expect($this->jeem->fresh()->sort_order)->toBe(1)
            ->and($this->alef->fresh()->sort_order)->toBe(2)
            ->and($this->baa->fresh()->sort_order)->toBe(3);
    });

    it('lists the templates in the stored order', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('service-templates.reorder'), ['ids' => [$this->jeem->id, $this->baa->id, $this->alef->id]]);

        $this->actingAs($this->superAdmin)
            ->get(route('service-templates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('templates.data.0.id', $this->jeem->id)
                ->where('templates.data.1.id', $this->baa->id)
                ->where('templates.data.2.id', $this->alef->id));
    });

    it('hands the POS the same order', function () {
        $branch = Branch::factory()->create();

        foreach ([$this->alef, $this->baa, $this->jeem] as $template) {
            BranchService::create([
                'branch_id' => $branch->id,
                'service_template_id' => $template->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 0,
                'is_active' => true,
            ]);
        }

        $this->actingAs($this->superAdmin)
            ->post(route('service-templates.reorder'), ['ids' => [$this->jeem->id, $this->alef->id, $this->baa->id]]);

        $employee = User::factory()->create(['branch_id' => $branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->get(route('pos.service.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('services.0.name', $this->jeem->name)
                ->where('services.1.name', $this->alef->name)
                ->where('services.2.name', $this->baa->name));
    });

    it('rejects an unknown template id', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('service-templates.reorder'), ['ids' => [999999]])
            ->assertSessionHasErrors('ids.0');
    });

    it('keeps employees away from reordering', function () {
        $employee = User::factory()->create();
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->post(route('service-templates.reorder'), ['ids' => [$this->alef->id]])
            ->assertForbidden();
    });
});
