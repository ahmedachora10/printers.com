<?php

use App\Models\Branch;
use App\Models\City;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ServiceTemplate Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole('super-admin');
        $this->actingAs($this->superAdmin);
    });

    // ── INDEX ──────────────────────────────────────────────────────

    it('allows super-admin to view service template list', function () {
        ServiceTemplate::factory()->count(3)->create();

        $this->get(route('service-templates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('service-templates/index'));
    });

    it('prevents non-super-admin from viewing service templates', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->get(route('service-templates.index'))->assertForbidden();
    });

    // ── STORE ──────────────────────────────────────────────────────

    it('creates a service template with valid data', function () {
        $this->post(route('service-templates.store'), [
            'name' => 'طباعة رقمية',
            'is_active' => true,
        ])->assertRedirect(route('service-templates.index'));

        $this->assertDatabaseHas('service_templates', ['name' => 'طباعة رقمية']);
    });

    it('creates a service template with description', function () {
        $this->post(route('service-templates.store'), [
            'name' => 'طباعة أوفست',
            'description' => 'خدمة الطباعة بتقنية الأوفست',
            'is_active' => true,
        ])->assertRedirect(route('service-templates.index'));

        $this->assertDatabaseHas('service_templates', [
            'name' => 'طباعة أوفست',
            'description' => 'خدمة الطباعة بتقنية الأوفست',
        ]);
    });

    it('fails to create a service template without required name', function () {
        $this->post(route('service-templates.store'), [
            'is_active' => true,
        ])->assertSessionHasErrors(['name']);
    });

    it('fails to create a service template with name exceeding 255 characters', function () {
        $this->post(route('service-templates.store'), [
            'name' => str_repeat('أ', 256),
            'is_active' => true,
        ])->assertSessionHasErrors(['name']);
    });

    // تاسك 45 قلب هذه القاعدة: مدير الفرع صار ينشئ خدماته بنفسه — لكن لفرعه
    // وحده، فمن لا فرع يملكه لا ينشئ شيئاً (وإلا خرجت خدمة عامة بالخطأ).
    // الحالة الموجبة في BranchOwnedServiceTemplateTest.
    it('prevents a branch-admin with no owned branch from creating service templates', function () {
        $branchAdmin = User::factory()->create();
        $branchAdmin->addRole('branch-admin');
        $this->actingAs($branchAdmin);

        $this->post(route('service-templates.store'), [
            'name' => 'تصميم',
            'is_active' => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('service_templates', ['name' => 'تصميم']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('updates a service template name', function () {
        $template = ServiceTemplate::factory()->create(['name' => 'طباعة قديمة']);

        $this->put(route('service-templates.update', $template), [
            'name' => 'طباعة رقمية محدثة',
            'is_active' => true,
        ])->assertRedirect(route('service-templates.index'));

        $this->assertDatabaseHas('service_templates', [
            'id' => $template->id,
            'name' => 'طباعة رقمية محدثة',
        ]);
    });

    it('deactivating a template does not cascade to branch services', function () {
        $city = City::factory()->create();
        $branch = Branch::factory()->create(['city_id' => $city->id]);
        $template = ServiceTemplate::factory()->create(['is_active' => true]);
        $template->branches()->attach($branch->id, [
            'base_commission_pct' => 10,
            'max_discount_pct' => 5,
            'is_tahazir' => false,
            'is_active' => true,
        ]);

        $this->put(route('service-templates.update', $template), [
            'name' => $template->name,
            'is_active' => false,
        ])->assertRedirect(route('service-templates.index'));

        $this->assertDatabaseHas('service_templates', ['id' => $template->id, 'is_active' => false]);
        $this->assertDatabaseHas('branch_services', [
            'service_template_id' => $template->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    });

    it('prevents employee from updating service templates', function () {
        $template = ServiceTemplate::factory()->create();
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->put(route('service-templates.update', $template), [
            'name' => 'معدل',
            'is_active' => true,
        ])->assertForbidden();
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('deletes a service template with no associated branch services', function () {
        $template = ServiceTemplate::factory()->create();

        $this->delete(route('service-templates.destroy', $template))
            ->assertRedirect(route('service-templates.index'));

        $this->assertDatabaseMissing('service_templates', ['id' => $template->id]);
    });

    it('prevents deletion of a service template linked to branch services', function () {
        $city = City::factory()->create();
        $branch = Branch::factory()->create(['city_id' => $city->id]);
        $template = ServiceTemplate::factory()->create();
        $template->branches()->attach($branch->id, [
            'base_commission_pct' => 10,
            'max_discount_pct' => 5,
            'is_tahazir' => false,
            'is_active' => true,
        ]);

        $this->delete(route('service-templates.destroy', $template))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('service_templates', ['id' => $template->id]);
    });

    it('prevents employee from deleting service templates', function () {
        $template = ServiceTemplate::factory()->create();
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->delete(route('service-templates.destroy', $template))->assertForbidden();
    });
});
