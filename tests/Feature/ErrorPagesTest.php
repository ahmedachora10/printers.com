<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Error Pages', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    });

    it('renders the errors/403 Inertia page when authorization fails', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->get(route('branches.index'))
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('errors/403'));
    });

    it('renders the errors/403 Inertia page when Laratrust role middleware aborts', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        // agent-portal.index is guarded by `role:agent` middleware, which calls
        // abort(403) and throws a generic HttpException (not AccessDeniedHttpException).
        $this->get(route('agent-portal.index'))
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('errors/403'));
    });

    it('returns a 403 JSON payload for API requests', function () {
        $employee = User::factory()->create();
        $employee->addRole('employee');
        $this->actingAs($employee);

        $this->getJson(route('branches.index'))
            ->assertForbidden()
            ->assertJson(['status' => 403]);
    });

    it('renders the errors/404 Inertia page for unknown routes', function () {
        $this->get('/this-route-does-not-exist')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('errors/404'));
    });
});
