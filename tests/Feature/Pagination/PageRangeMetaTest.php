<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * تاسك 78: «عرض 11‑16 من أصل 16» في صفحةٍ تحمل صفّاً واحداً. الصفوف لم تضع —
 * الواجهة كانت تعيد حساب المدى بحجم صفحةٍ مفترض (10) بينما الكنترولرات تصفّح
 * بـ8 و12 و15 و20. فالمدى صار يصل من الخادم في كل ترويسة.
 */
describe('page range meta', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);
    });

    it('sends the real range for a screen paginated by 15', function () {
        ServiceTemplate::factory()->count(16)->create();

        $this->actingAs($this->superAdmin)
            ->get(route('service-templates.index', ['page' => 2]))
            ->assertInertia(fn ($page) => $page
                ->component('service-templates/index')
                ->where('templates.meta.from', 16)
                ->where('templates.meta.to', 16)
                ->where('templates.meta.total', 16)
                ->has('templates.data', 1));
    });

    it('sends the real range for a screen paginated by 8', function () {
        City::factory()->count(9)->create();

        $this->actingAs($this->superAdmin)
            ->get(route('cities.index', ['page' => 2]))
            ->assertInertia(fn ($page) => $page
                ->where('cities.meta.from', 9)
                ->where('cities.meta.to', 9)
                ->has('cities.data', 1));
    });

    it('sends a null range for a page with no results', function () {
        $this->actingAs($this->superAdmin)
            ->get(route('service-templates.index'))
            ->assertInertia(fn ($page) => $page
                ->where('templates.meta.total', 0)
                ->where('templates.meta.from', null)
                ->where('templates.meta.to', null));
    });

    it('sends the range on a meta built by BuildsPagedProps too', function () {
        $city = City::factory()->create();
        $branchAdmin = User::factory()->create();
        $branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $branch = Branch::factory()->create(['city_id' => $city->id, 'owner_id' => $branchAdmin->id]);
        $branchAdmin->update(['branch_id' => $branch->id]);

        $customer = Customer::factory()->create(['branch_id' => $branch->id]);

        // سجلّ نقاط العميل يُصفّح بعشرة عبر pagedProp — ترويسةٌ يبنيها النظام
        // بنفسه لا مُصفِّح Laravel، وكانت بلا مدى.
        LoyaltyTransaction::factory()->count(11)->create(['customer_id' => $customer->id]);

        $this->actingAs($branchAdmin)
            ->get(route('customers.show', [$customer, 'loyaltyPage' => 2]))
            ->assertInertia(fn ($page) => $page
                ->where('loyaltyHistory.meta.from', 11)
                ->where('loyaltyHistory.meta.to', 11)
                ->where('loyaltyHistory.meta.total', 11));
    });
});
