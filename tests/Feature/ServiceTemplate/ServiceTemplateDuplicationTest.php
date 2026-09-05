<?php

use App\Enums\Roles;
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
 * تاسك 83: تكرار الصنف بنفس الأسعار والمواصفات — نسخةٌ بفروعها وشروط كلٍّ منها،
 * تبدأ غير نشطة كي لا تصل نقطة البيع باسم «— نسخة».
 */
describe('Service template duplication', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->addRole(Roles::SUPER_ADMIN->value);

        $this->city = City::factory()->create();
        $this->branch = Branch::factory()->create(['city_id' => $this->city->id]);
        $this->otherBranch = Branch::factory()->create(['city_id' => $this->city->id]);

        $this->template = ServiceTemplate::factory()->create([
            'name' => 'استاند رول آب مقاس 200×200',
            'sort_order' => 4,
            'is_active' => true,
        ]);

        $this->terms = [
            'base_commission_pct' => 12.5,
            'max_discount_pct' => 7.5,
            'max_selling_price' => 300,
            'min_selling_price' => 90.25,
            'pricing_type' => 'sqm',
            'price_per_sqm' => 45.75,
            'agent_commission_per_sqm' => 3.5,
            'note_examples' => ['وجهان', 'مع حقيبة'],
            'is_tahazir' => true,
            'has_materials' => true,
            'materials_cost' => 20.5,
            'is_active' => true,
        ];

        $this->template->branches()->attach($this->branch->id, $this->terms);
        $this->template->branches()->attach($this->otherBranch->id, [
            ...$this->terms,
            'base_commission_pct' => 9,
            'pricing_type' => 'unit',
            'note_examples' => [],
        ]);
    });

    it('copies the template with every branch link and its terms', function () {
        $this->actingAs($this->superAdmin)
            ->post(route('service-templates.duplicate', $this->template))
            ->assertRedirect();

        $copy = ServiceTemplate::query()->where('id', '<>', $this->template->id)->firstOrFail();

        expect($copy->name)->toBe('استاند رول آب مقاس 200×200 — نسخة')
            ->and($copy->is_active)->toBeFalse()
            // ترتيب الأصل نفسه، فتقع النسخة بجواره لا في ذيل القائمة.
            ->and($copy->sort_order)->toBe($this->template->sort_order)
            ->and($copy->branches()->count())->toBe(2);

        $copied = BranchService::query()
            ->where('service_template_id', $copy->id)
            ->where('branch_id', $this->branch->id)
            ->firstOrFail();

        expect((float) $copied->base_commission_pct)->toBe(12.5)
            ->and((float) $copied->max_discount_pct)->toBe(7.5)
            ->and((float) $copied->max_selling_price)->toBe(300.0)
            ->and((float) $copied->min_selling_price)->toBe(90.25)
            ->and($copied->pricing_type->value)->toBe('sqm')
            ->and((float) $copied->price_per_sqm)->toBe(45.75)
            ->and((float) $copied->agent_commission_per_sqm)->toBe(3.5)
            ->and($copied->note_examples)->toBe(['وجهان', 'مع حقيبة'])
            ->and($copied->is_tahazir)->toBeTrue()
            ->and($copied->has_materials)->toBeTrue()
            ->and((float) $copied->materials_cost)->toBe(20.5)
            ->and($copied->is_active)->toBeTrue();
    });

    it('carries the per-employee commission rates onto the copy', function () {
        $employee = User::factory()->create(['branch_id' => $this->branch->id, 'base_commission_pct' => 35]);
        $employee->addRole(Roles::EMPLOYEE->value);
        $excluded = User::factory()->create(['branch_id' => $this->branch->id, 'base_commission_pct' => 40]);
        $excluded->addRole(Roles::EMPLOYEE->value);

        $source = BranchService::query()
            ->where('service_template_id', $this->template->id)
            ->where('branch_id', $this->branch->id)
            ->firstOrFail();

        UserService::query()->create([
            'user_id' => $employee->id,
            'branch_service_id' => $source->id,
            'commission_override_pct' => 6.25,
        ]);

        $this->actingAs($this->superAdmin)->post(route('service-templates.duplicate', $this->template));

        $copied = BranchService::query()
            ->where('service_template_id', '<>', $this->template->id)
            ->where('branch_id', $this->branch->id)
            ->firstOrFail();

        $rates = UserService::query()->where('branch_service_id', $copied->id)->get();

        // من له نسبة يرثها، ومن استُثني من الأصل يبقى مستثنى في التوأم — لا
        // تُكتب له العمولة الأساسية (تاسك 85 يخصّ خدمةً جديدة لا نسخة).
        expect($rates)->toHaveCount(1)
            ->and($rates->first()->user_id)->toBe($employee->id)
            ->and((float) $rates->first()->commission_override_pct)->toBe(6.25);
    });

    it('numbers the suffix when the name is already taken', function () {
        $this->actingAs($this->superAdmin)->post(route('service-templates.duplicate', $this->template));
        $this->actingAs($this->superAdmin)->post(route('service-templates.duplicate', $this->template));
        $this->actingAs($this->superAdmin)->post(route('service-templates.duplicate', $this->template));

        expect(ServiceTemplate::query()->pluck('name')->all())->toBe([
            'استاند رول آب مقاس 200×200',
            'استاند رول آب مقاس 200×200 — نسخة',
            'استاند رول آب مقاس 200×200 — نسخة 2',
            'استاند رول آب مقاس 200×200 — نسخة 3',
        ]);
    });

    it('leaves the original untouched', function () {
        $this->actingAs($this->superAdmin)->post(route('service-templates.duplicate', $this->template));

        expect($this->template->fresh()->is_active)->toBeTrue()
            ->and($this->template->fresh()->name)->toBe('استاند رول آب مقاس 200×200')
            ->and(BranchService::query()->where('service_template_id', $this->template->id)->count())->toBe(2);
    });

    it('duplicates a template with no branch links at all', function () {
        $bare = ServiceTemplate::factory()->create(['name' => 'خدمة بلا فروع']);

        $this->actingAs($this->superAdmin)
            ->post(route('service-templates.duplicate', $bare))
            ->assertRedirect();

        expect(ServiceTemplate::query()->where('name', 'خدمة بلا فروع — نسخة')->exists())->toBeTrue();
    });

    it('forbids anyone but a super-admin', function () {
        $branchAdmin = User::factory()->create();
        $branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $branchAdmin->id]);
        $branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->actingAs($branchAdmin)
            ->post(route('service-templates.duplicate', $this->template))
            ->assertForbidden();

        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($employee)
            ->post(route('service-templates.duplicate', $this->template))
            ->assertForbidden();

        expect(ServiceTemplate::query()->count())->toBe(1);
    });
});
