<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

describe('Shared branch identity', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['name' => 'فرع الرياض']);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
    });

    it('shares the branch name and logo with a user whose branch has one', function () {
        $this->branch->addMedia(UploadedFile::fake()->image('logo.png'))->toMediaCollection('logo');

        $this->actingAs($this->employee)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.branch.name', 'فرع الرياض')
                ->where('auth.branch.logoUrl', fn (?string $url) => is_string($url) && $url !== ''));
    });

    it('shares a null logo for a branch that never uploaded one', function () {
        $this->actingAs($this->employee)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.branch.name', 'فرع الرياض')
                ->where('auth.branch.logoUrl', null));
    });

    it('shares no branch at all for a super-admin', function () {
        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.branch', null));
    });

    it('resolves the branch of a branch-admin through the branch they manage', function () {
        $branchAdmin = User::factory()->create(['branch_id' => null]);
        $branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $branchAdmin->id]);

        $this->actingAs($branchAdmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.branch.name', 'فرع الرياض'));
    });
});
