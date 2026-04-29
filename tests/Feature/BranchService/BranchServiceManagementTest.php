<?php

use App\Models\Branch;
use App\Models\BranchService;
use App\Models\City;
use App\Models\ServiceTemplate;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('BranchService Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->city     = City::factory()->create();
        $this->branch   = Branch::factory()->create(['city_id' => $this->city->id]);
        $this->template = ServiceTemplate::factory()->create();

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole('super-admin');
        $this->actingAs($this->superAdmin);
    });

    // ── STORE (ATTACH) ─────────────────────────────────────────────

    it('super-admin can attach a branch to a service template', function () {
        $this->post(route('branch-services.store'), [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 10.00,
            'max_discount_pct'    => 5.00,
            'is_tahazir'          => false,
            'is_active'           => true,
        ])->assertRedirect(route('service-templates.index'));

        $this->assertDatabaseHas('branch_services', [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 10.00,
        ]);
    });

    it('branch-admin can attach their own branch to a service template', function () {
        $branchAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $branchAdmin->addRole('branch-admin');
        $this->actingAs($branchAdmin);

        $this->post(route('branch-services.store'), [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 8.00,
            'max_discount_pct'    => 3.00,
            'is_tahazir'          => false,
            'is_active'           => true,
        ])->assertRedirect(route('service-templates.index'));

        $this->assertDatabaseHas('branch_services', [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
        ]);
    });

    it('prevents employee from attaching a branch service', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->post(route('branch-services.store'), [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 10.00,
            'max_discount_pct'    => 5.00,
        ])->assertForbidden();
    });

    it('prevents duplicate (branch, template) pairs', function () {
        $this->template->branches()->attach($this->branch->id, [
            'base_commission_pct' => 10,
            'max_discount_pct'    => 5,
            'is_tahazir'          => false,
            'is_active'           => true,
        ]);

        $this->post(route('branch-services.store'), [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 10.00,
            'max_discount_pct'    => 5.00,
        ])->assertSessionHasErrors(['branch_id']);
    });

    it('validates commission pct does not exceed 100', function () {
        $this->post(route('branch-services.store'), [
            'service_template_id' => $this->template->id,
            'branch_id'           => $this->branch->id,
            'base_commission_pct' => 150.00,
            'max_discount_pct'    => 5.00,
        ])->assertSessionHasErrors(['base_commission_pct']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('super-admin can update a branch service pivot', function () {
        $this->template->branches()->attach($this->branch->id, [
            'base_commission_pct' => 10,
            'max_discount_pct'    => 5,
            'is_tahazir'          => false,
            'is_active'           => true,
        ]);

        $branchService = BranchService::where('service_template_id', $this->template->id)
            ->where('branch_id', $this->branch->id)
            ->first();

        $this->put(route('branch-services.update', $branchService), [
            'base_commission_pct' => 15.00,
            'max_discount_pct'    => 8.00,
            'is_tahazir'          => true,
            'is_active'           => true,
        ])->assertRedirect(route('service-templates.index'));

        $this->assertDatabaseHas('branch_services', [
            'id'                  => $branchService->id,
            'base_commission_pct' => 15.00,
            'is_tahazir'          => true,
        ]);
    });

    it('branch-admin cannot update another branch service', function () {
        $otherCity   = City::factory()->create();
        $otherBranch = Branch::factory()->create(['city_id' => $otherCity->id]);
        $branchAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $branchAdmin->addRole('branch-admin');
        $this->actingAs($branchAdmin);

        $this->template->branches()->attach($otherBranch->id, [
            'base_commission_pct' => 10,
            'max_discount_pct'    => 5,
            'is_tahazir'          => false,
            'is_active'           => true,
        ]);

        $branchService = BranchService::where('service_template_id', $this->template->id)
            ->where('branch_id', $otherBranch->id)
            ->first();

        $this->put(route('branch-services.update', $branchService), [
            'base_commission_pct' => 20.00,
            'max_discount_pct'    => 10.00,
            'is_tahazir'          => false,
            'is_active'           => true,
        ])->assertForbidden();
    });

    // ── DESTROY (DETACH) ───────────────────────────────────────────

    it('super-admin can detach a branch from a service template', function () {
        $this->template->branches()->attach($this->branch->id, [
            'base_commission_pct' => 10,
            'max_discount_pct'    => 5,
            'is_tahazir'          => false,
            'is_active'           => true,
        ]);

        $branchService = BranchService::where('service_template_id', $this->template->id)
            ->where('branch_id', $this->branch->id)
            ->first();

        $this->delete(route('branch-services.destroy', $branchService))
            ->assertRedirect(route('service-templates.index'));

        $this->assertDatabaseMissing('branch_services', ['id' => $branchService->id]);
    });

    it('ensures BranchService pivot has no SoftDeletes trait', function () {
        $traits = class_uses_recursive(BranchService::class);

        expect($traits)->not->toContain(\Illuminate\Database\Eloquent\SoftDeletes::class);
    });
});
