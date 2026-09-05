<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Error Pages', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
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

    // كان العنوان المجهول يُرمى من الموجِّه قبل وسائط الويب، فتصل الصفحةُ
    // بلا auth وتنكسر في المتصفّح. المشاركات دليلُ مرورها بالوسائط.
    it('shares the usual Inertia props on the 404 page of an unknown route', function () {
        $user = User::factory()->create();
        $user->addRole('branch-admin');

        $this->actingAs($user)
            ->get('/this-route-does-not-exist')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page
                ->component('errors/404')
                ->where('auth.user.id', $user->id)
            );
    });

    it('returns a 404 JSON payload for API requests to unknown routes', function () {
        $this->getJson('/this-route-does-not-exist')
            ->assertNotFound()
            ->assertJson(['status' => 404]);
    });
});
