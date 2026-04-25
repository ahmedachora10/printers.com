<?php

use App\Models\City;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('City Management', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole('super-admin');
        $this->actingAs($this->superAdmin);
    });

    // ── INDEX ──────────────────────────────────────────────────────

    it('allows super-admin to view city list', function () {
        City::factory()->count(3)->create();

        $this->get(route('cities.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('cities/index'));
    });

    it('prevents non-super-admin from viewing city list', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->get(route('cities.index'))->assertForbidden();
    });

    // ── STORE ──────────────────────────────────────────────────────

    it('creates a city with valid data', function () {
        $this->post(route('cities.store'), ['name' => 'الرياض', 'is_active' => true])
            ->assertRedirect(route('cities.index'));

        $this->assertDatabaseHas('cities', ['name' => 'الرياض', 'is_active' => true]);
    });

    it('fails to create a city without a name', function () {
        $this->post(route('cities.store'), [])
            ->assertSessionHasErrors(['name']);
    });

    it('fails to create a city with a name exceeding 255 characters', function () {
        $this->post(route('cities.store'), ['name' => str_repeat('أ', 256)])
            ->assertSessionHasErrors(['name']);
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('updates a city name', function () {
        $city = City::factory()->create(['name' => 'الرياض']);

        $this->put(route('cities.update', $city), ['name' => 'جدة'])
            ->assertRedirect(route('cities.index'));

        $this->assertDatabaseHas('cities', ['id' => $city->id, 'name' => 'جدة']);
    });

    // ── TOGGLE STATUS ──────────────────────────────────────────────

    it('toggles city status from active to inactive', function () {
        $city = City::factory()->create(['is_active' => true]);

        $this->patch(route('cities.toggle-status', $city))
            ->assertRedirect(route('cities.index'));

        $this->assertDatabaseHas('cities', ['id' => $city->id, 'is_active' => false]);
    });

    it('toggles city status from inactive to active', function () {
        $city = City::factory()->create(['is_active' => false]);

        $this->patch(route('cities.toggle-status', $city))
            ->assertRedirect(route('cities.index'));

        $this->assertDatabaseHas('cities', ['id' => $city->id, 'is_active' => true]);
    });

    // ── DELETE ─────────────────────────────────────────────────────

    it('deletes a city not assigned to any branch', function () {
        $city = City::factory()->create();

        $this->delete(route('cities.destroy', $city))
            ->assertRedirect(route('cities.index'));

        $this->assertDatabaseMissing('cities', ['id' => $city->id]);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('prevents branch-admin from creating cities', function () {
        $branchAdmin = User::factory()->create();
        $branchAdmin->addRole('branch-admin');
        $this->actingAs($branchAdmin);

        $this->post(route('cities.store'), ['name' => 'تبوك'])
            ->assertForbidden();
    });

    it('prevents accountant from updating cities', function () {
        $accountant = User::factory()->create();
        $accountant->addRole('accountant');
        $this->actingAs($accountant);

        $city = City::factory()->create();

        $this->put(route('cities.update', $city), ['name' => 'حائل'])
            ->assertForbidden();
    });
});
