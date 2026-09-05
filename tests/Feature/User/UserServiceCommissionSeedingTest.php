<?php

use App\Actions\BranchService\AttachBranchServiceAction;
use App\Actions\UserService\SeedUserServiceCommissionsAction;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\City;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * تاسك 85: ربط خدمة جديدة بفرعٍ يكتب لكل موظف نشط فيه صفَّ عمولة بقيمة عمولته
 * الأساسية — بلا نقض قاعدة M15 («لا صفَّ = صفر بالمئة»)، فالصفّ يُكتب فعلاً.
 */
describe('seeding user service commissions on attach', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->city = City::factory()->create();

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole('branch-admin');

        $this->branch = Branch::factory()->create([
            'city_id' => $this->city->id,
            'owner_id' => $this->branchAdmin->id,
        ]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->template = ServiceTemplate::factory()->create();

        $this->employee = function (Branch $branch, float $pct, bool $active = true) {
            $user = User::factory()->create([
                'branch_id' => $branch->id,
                'base_commission_pct' => $pct,
                'is_active' => $active,
            ]);
            $user->addRole('employee');

            return $user;
        };

        $this->attach = fn (array $overrides = []) => app(AttachBranchServiceAction::class)->handle([
            'service_template_id' => $this->template->id,
            'branch_id' => $this->branch->id,
            'base_commission_pct' => 10,
            'max_discount_pct' => 5,
            ...$overrides,
        ]);
    });

    it('writes a row per employee at their own base commission', function () {
        $first = ($this->employee)($this->branch, 35);
        $second = ($this->employee)($this->branch, 12.5);
        $third = ($this->employee)($this->branch, 20);

        $service = ($this->attach)();

        expect(UserService::where('branch_service_id', $service->id)->count())->toBe(3);

        expect((float) UserService::where('branch_service_id', $service->id)->where('user_id', $first->id)->value('commission_override_pct'))->toBe(35.0);
        expect((float) UserService::where('branch_service_id', $service->id)->where('user_id', $second->id)->value('commission_override_pct'))->toBe(12.5);
        expect((float) UserService::where('branch_service_id', $service->id)->where('user_id', $third->id)->value('commission_override_pct'))->toBe(20.0);
    });

    it('skips an employee whose base commission is zero', function () {
        $earning = ($this->employee)($this->branch, 30);
        $zero = ($this->employee)($this->branch, 0);

        $service = ($this->attach)();

        expect(UserService::where('branch_service_id', $service->id)->pluck('user_id')->all())
            ->toBe([$earning->id]);
    });

    it('skips an inactive employee', function () {
        ($this->employee)($this->branch, 30, active: false);

        $service = ($this->attach)();

        expect(UserService::where('branch_service_id', $service->id)->count())->toBe(0);
    });

    it('does not touch employees of another branch', function () {
        $otherAdmin = User::factory()->create();
        $otherAdmin->addRole('branch-admin');
        $otherBranch = Branch::factory()->create(['city_id' => $this->city->id, 'owner_id' => $otherAdmin->id]);

        $mine = ($this->employee)($this->branch, 25);
        ($this->employee)($otherBranch, 40);

        $service = ($this->attach)();

        expect(UserService::where('branch_service_id', $service->id)->pluck('user_id')->all())
            ->toBe([$mine->id]);
    });

    it('leaves accountants and branch admins without a row', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id, 'base_commission_pct' => 40]);
        $accountant->addRole('accountant');
        $this->branchAdmin->update(['base_commission_pct' => 50]);

        $service = ($this->attach)();

        expect(UserService::where('branch_service_id', $service->id)->count())->toBe(0);
    });

    it('does not write a second row when the pair already exists', function () {
        $employee = ($this->employee)($this->branch, 35);
        $service = ($this->attach)();

        UserService::where('branch_service_id', $service->id)
            ->where('user_id', $employee->id)
            ->update(['commission_override_pct' => 7]);

        app(SeedUserServiceCommissionsAction::class)->handle($service);

        expect(UserService::where('branch_service_id', $service->id)->count())->toBe(1)
            ->and((float) UserService::where('branch_service_id', $service->id)->value('commission_override_pct'))->toBe(7.0);
    });

    it('leaves services attached earlier untouched — no retroactive rows', function () {
        $earlier = ServiceTemplate::factory()->create();
        $earlierService = app(AttachBranchServiceAction::class)->handle([
            'service_template_id' => $earlier->id,
            'branch_id' => $this->branch->id,
            'base_commission_pct' => 10,
            'max_discount_pct' => 5,
        ]);

        // The employee joins (or gets their rate) only after that first service.
        ($this->employee)($this->branch, 35);

        $newService = ($this->attach)();

        expect(UserService::where('branch_service_id', $earlierService->id)->count())->toBe(0)
            ->and(UserService::where('branch_service_id', $newService->id)->count())->toBe(1);
    });

    it('seeds rates through the branch-services screen too', function () {
        $employee = ($this->employee)($this->branch, 18);

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $this->template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
            ])
            ->assertRedirect();

        $service = BranchService::where('service_template_id', $this->template->id)->firstOrFail();

        expect((float) UserService::where('branch_service_id', $service->id)->where('user_id', $employee->id)->value('commission_override_pct'))
            ->toBe(18.0);
    });
});
