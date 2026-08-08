<?php

use App\Models\Branch;
use App\Models\City;
use App\Models\User;
use App\Notifications\BranchProfileUpdatedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

describe('Branch profile self-service', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        // A branch-admin is linked to the branch they manage through
        // `branches.owner_id`, not their own `users.branch_id` column.
        $this->branchAdmin = User::factory()->create(['branch_id' => null]);
        $this->branchAdmin->addRole('branch-admin');
        $this->branch = Branch::factory()->create([
            'owner_id' => $this->branchAdmin->id,
            'name' => 'فرع الرياض',
            'tax_number' => '300000000000003',
            'vat_rate_override' => 15,
        ]);

        $this->actingAs($this->branchAdmin);
    });

    // ── INDEX ──────────────────────────────────────────────────────

    it('passes the owned branch to the settings page', function () {
        $this->get(route('app-settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('branchProfile.id', $this->branch->id)
                ->where('branchProfile.name', 'فرع الرياض')
                ->has('cities')
            );
    });

    it('withholds the branch profile from a super-admin who owns no branch', function () {
        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole('super-admin');
        $this->actingAs($superAdmin);

        $this->get(route('app-settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('branchProfile', null));
    });

    // ── UPDATE ─────────────────────────────────────────────────────

    it('lets a branch-admin update their own branch data', function () {
        $city = City::factory()->create();

        $this->put(route('app-settings.update-branch-profile'), [
            'name' => 'فرع الرياض الرئيسي',
            'city_id' => $city->id,
            'phone' => '0501234567',
            'address' => 'شارع العليا',
            'business_type' => 'طباعة',
            'commercial_reg_no' => '1010101010',
            'tax_number' => '300000000000003',
            'vat_rate_override' => 5,
        ])->assertRedirect();

        $this->branch->refresh();

        expect($this->branch->name)->toBe('فرع الرياض الرئيسي');
        expect($this->branch->city_id)->toBe($city->id);
        expect($this->branch->phone)->toBe('0501234567');
        expect($this->branch->commercial_reg_no)->toBe('1010101010');
        expect((float) $this->branch->vat_rate_override)->toBe(5.0);
    });

    it('ignores owner_id and is_active even when they are submitted', function () {
        $otherAdmin = User::factory()->create(['branch_id' => null]);
        $otherAdmin->addRole('branch-admin');

        $this->put(route('app-settings.update-branch-profile'), [
            'name' => $this->branch->name,
            'city_id' => $this->branch->city_id,
            'vat_rate_override' => 15,
            'owner_id' => $otherAdmin->id,
            'is_active' => false,
        ])->assertRedirect();

        $this->branch->refresh();

        expect($this->branch->owner_id)->toBe($this->branchAdmin->id);
        expect($this->branch->is_active)->toBeTrue();
    });

    it('replaces the branch logo', function () {
        $this->put(route('app-settings.update-branch-profile'), [
            'name' => $this->branch->name,
            'city_id' => $this->branch->city_id,
            'vat_rate_override' => 15,
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertRedirect();

        expect($this->branch->refresh()->getFirstMedia('logo'))->not->toBeNull();
    });

    it('fails validation when the name is missing or the vat rate is out of range', function () {
        $this->put(route('app-settings.update-branch-profile'), [
            'name' => '',
            'city_id' => $this->branch->city_id,
            'vat_rate_override' => 150,
        ])->assertSessionHasErrors(['name', 'vat_rate_override']);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('forbids a branch-admin who owns no branch', function () {
        $orphan = User::factory()->create(['branch_id' => null]);
        $orphan->addRole('branch-admin');
        $this->actingAs($orphan);

        $this->put(route('app-settings.update-branch-profile'), [
            'name' => 'محاولة',
            'city_id' => $this->branch->city_id,
            'vat_rate_override' => 15,
        ])->assertForbidden();

        expect($this->branch->refresh()->name)->toBe('فرع الرياض');
    });

    it('forbids an accountant from reaching the endpoint', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole('accountant');
        $this->actingAs($accountant);

        $this->put(route('app-settings.update-branch-profile'), [
            'name' => 'محاولة',
            'city_id' => $this->branch->city_id,
            'vat_rate_override' => 15,
        ])->assertForbidden();
    });

    // ── AUDIT & NOTIFICATION ───────────────────────────────────────

    it('logs the change with the editor as causer', function () {
        $this->put(route('app-settings.update-branch-profile'), [
            'name' => 'اسم جديد',
            'city_id' => $this->branch->city_id,
            'vat_rate_override' => 15,
        ])->assertRedirect();

        $activity = Activity::inLog('branches')->latest('id')->first();

        expect($activity)->not->toBeNull();
        expect($activity->description)->toBe('updated own branch profile');
        expect($activity->causer_id)->toBe($this->branchAdmin->id);
        expect($activity->subject_id)->toBe($this->branch->id);
        expect($activity->properties['changed'])->toContain('name');
    });

    it('notifies super-admins when an invoice-facing field changes', function () {
        Notification::fake();

        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole('super-admin');

        $this->put(route('app-settings.update-branch-profile'), [
            'name' => $this->branch->name,
            'city_id' => $this->branch->city_id,
            'tax_number' => '399999999999993',
            'vat_rate_override' => 15,
        ])->assertRedirect();

        Notification::assertSentTo(
            $superAdmin,
            BranchProfileUpdatedNotification::class,
            function (BranchProfileUpdatedNotification $notification) use ($superAdmin) {
                $payload = $notification->toArray($superAdmin);

                return $payload['type'] === 'branch_profile_updated'
                    && str_contains($payload['body'], 'الرقم الضريبي');
            },
        );
    });

    it('stays quiet when only contact details change', function () {
        Notification::fake();

        $superAdmin = User::factory()->create(['branch_id' => null]);
        $superAdmin->addRole('super-admin');

        $this->put(route('app-settings.update-branch-profile'), [
            'name' => $this->branch->name,
            'city_id' => $this->branch->city_id,
            'phone' => '0555555555',
            'address' => 'حي النخيل',
            'tax_number' => $this->branch->tax_number,
            'vat_rate_override' => 15,
        ])->assertRedirect();

        Notification::assertNothingSent();

        expect($this->branch->refresh()->phone)->toBe('0555555555');
    });
});
