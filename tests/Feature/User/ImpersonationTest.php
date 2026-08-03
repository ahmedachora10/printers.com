<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

describe('User Impersonation', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
    });

    it('lets a super-admin impersonate an employee and return', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('users.impersonate', $this->employee))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->employee->fresh());
        expect(session('impersonator_id'))->toBe($this->superAdmin->id);

        $this->delete(route('impersonate.leave'))
            ->assertRedirect(route('users.index'));

        $this->assertAuthenticatedAs($this->superAdmin->fresh());
        expect(session()->has('impersonator_id'))->toBeFalse();
    });

    it('lets a branch-admin impersonate an employee in their own branch', function () {
        $this->actingAs($this->branchAdmin)
            ->post(route('users.impersonate', $this->employee))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->employee->fresh());
    });

    it('forbids a branch-admin from impersonating staff in another branch', function () {
        $otherBranch = Branch::factory()->create();
        $outsider = User::factory()->create(['branch_id' => $otherBranch->id]);
        $outsider->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->branchAdmin)
            ->post(route('users.impersonate', $outsider))
            ->assertForbidden();

        $this->assertAuthenticatedAs($this->branchAdmin->fresh());
    });

    it('forbids impersonating another admin', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('users.impersonate', $this->branchAdmin))
            ->assertForbidden();
    });

    it('forbids impersonating yourself', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('users.impersonate', $this->superAdmin))
            ->assertForbidden();
    });

    it('forbids a non-admin from impersonating anyone', function () {
        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->employee)
            ->post(route('users.impersonate', $other))
            ->assertForbidden();
    });

    it('records the impersonation in the activity log', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('users.impersonate', $this->employee));

        expect(
            Activity::query()
                ->where('log_name', 'security')
                ->where('description', 'started impersonation')
                ->where('causer_id', $this->superAdmin->id)
                ->where('subject_id', $this->employee->id)
                ->exists()
        )->toBeTrue();
    });

    it('exposes the impersonating banner state to the frontend', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('users.impersonate', $this->employee));

        $this->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('auth.impersonating.active', true)
                ->where('auth.impersonating.viewingName', $this->employee->name));
    });

    it('forbids impersonating a deactivated account', function () {
        $this->employee->update(['is_active' => false]);

        $this->actingAs($this->superAdmin)
            ->post(route('users.impersonate', $this->employee))
            ->assertForbidden();

        $this->assertAuthenticatedAs($this->superAdmin->fresh());
    });

    it('returns the admin to their own account if the impersonated user is deactivated mid-session', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('users.impersonate', $this->employee));

        $this->employee->update(['is_active' => false]);

        // The test app is reused between requests; drop the resolved guard so the
        // next request re-reads the user from the session like a real one does.
        $this->app['auth']->forgetGuards();

        $this->get(route('dashboard'))->assertRedirect(route('users.index'));

        $this->assertAuthenticatedAs($this->superAdmin->fresh());
        expect(session()->has('impersonator_id'))->toBeFalse();
    });

    it('does nothing when leaving without an active impersonation', function () {
        $this->actingAs($this->superAdmin)
            ->delete(route('impersonate.leave'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->superAdmin->fresh());
    });
});
