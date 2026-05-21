<?php

use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('AppSetting Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create(['branch_id' => null]);
        $this->branchAdmin->addRole('branch-admin');
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);

        $this->actingAs($this->branchAdmin);
    });

    // ── INDEX ──────────────────────────────────────────────────────

    it('allows branch-admin to view settings page', function () {
        $this->get(route('app-settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('app-settings/index'));
    });

    it('allows super-admin to view settings page', function () {
        $superAdmin = User::factory()->create();
        $superAdmin->addRole('super-admin');
        $this->actingAs($superAdmin);

        $this->get(route('app-settings.index'))->assertOk();
    });

    it('prevents employee from viewing settings page', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->get(route('app-settings.index'))->assertForbidden();
    });

    // ── UPDATE GENERAL (super-admin) ───────────────────────────────

    it('allows super-admin to update global settings', function () {
        $superAdmin = User::factory()->create();
        $superAdmin->addRole('super-admin');
        $this->actingAs($superAdmin);

        $this->put(route('app-settings.update-general'), [
            'app_name'        => 'مركز الطباعة الجديد',
            'default_vat_pct' => '15.00',
        ])->assertRedirect();

        expect(Setting::get('app_name'))->toBe('مركز الطباعة الجديد');
        expect(Setting::get('default_vat_pct'))->toBe('15.00');
    });

    it('fails validation when default_vat_pct exceeds 100', function () {
        $superAdmin = User::factory()->create();
        $superAdmin->addRole('super-admin');
        $this->actingAs($superAdmin);

        $this->put(route('app-settings.update-general'), ['default_vat_pct' => '150'])
            ->assertSessionHasErrors(['default_vat_pct']);
    });

    // ── UPDATE INVENTORY ALERTS ────────────────────────────────────

    it('allows branch-admin to update inventory alerts', function () {
        $this->put(route('app-settings.update-inventory-alerts'), [
            'min_stock_alert_threshold' => 5,
        ])->assertRedirect();

        expect(Setting::get('min_stock_alert_threshold', $this->branch->id))->toBe('5');
    });

    it('fails validation when min_stock_alert_threshold is negative', function () {
        $this->put(route('app-settings.update-inventory-alerts'), [
            'min_stock_alert_threshold' => -1,
        ])->assertSessionHasErrors(['min_stock_alert_threshold']);
    });
});
