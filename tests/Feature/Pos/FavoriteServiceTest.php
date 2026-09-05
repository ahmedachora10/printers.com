<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** خدمة فرعٍ بسيطة — الـPivot لا يعيد المفتاح عند الإنشاء، فتُعاد قراءتها. */
function makeFavoritableService(Branch $branch, ?string $name = null): BranchService
{
    $template = ServiceTemplate::factory()->create($name ? ['name' => $name] : []);

    BranchService::create([
        'branch_id' => $branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 0,
        'is_active' => true,
    ]);

    return BranchService::where('branch_id', $branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

/**
 * تاسك 76: خدمات مفضّلة لكل موظف تُرفع أعلى قائمة نقطة البيع.
 */
describe('Favorite services', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create();

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->service = makeFavoritableService($this->branch, 'أ خدمة');
    });

    it('marks a favourited service in the POS props', function () {
        $this->actingAs($this->employee)
            ->post(route('pos.service.favorites.toggle', $this->service->id))
            ->assertRedirect();

        $this->assertDatabaseHas('user_favorite_services', [
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
        ]);

        $this->actingAs($this->employee)
            ->get(route('pos.service.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('services.0.isFavorite', true));
    });

    it('leaves an unfavourited service false', function () {
        $this->actingAs($this->employee)
            ->get(route('pos.service.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('services.0.isFavorite', false));
    });

    it('deletes the row on a second toggle instead of duplicating it', function () {
        $this->actingAs($this->employee)->post(route('pos.service.favorites.toggle', $this->service->id));
        $this->actingAs($this->employee)->post(route('pos.service.favorites.toggle', $this->service->id));

        $this->assertDatabaseMissing('user_favorite_services', [
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
        ]);

        expect(DB::table('user_favorite_services')->count())->toBe(0);
    });

    it('refuses a service from another branch', function () {
        $otherService = makeFavoritableService(Branch::factory()->create(), 'خدمة فرع آخر');

        $this->actingAs($this->employee)
            ->post(route('pos.service.favorites.toggle', $otherService->id))
            ->assertForbidden();

        expect(DB::table('user_favorite_services')->count())->toBe(0);
    });

    it('keeps one employee out of another employee favourites', function () {
        $colleague = User::factory()->create(['branch_id' => $this->branch->id]);
        $colleague->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->employee)->post(route('pos.service.favorites.toggle', $this->service->id));

        // التفضيل شخصيّ: زميلُه يفتح الشاشة نفسها فلا يرى نجمته.
        $this->actingAs($colleague)
            ->get(route('pos.service.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('services.0.isFavorite', false));
    });
});
