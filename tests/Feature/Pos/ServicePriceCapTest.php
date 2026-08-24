<?php

use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * سقف سعر البيع (أعلى سعر للبيع): خانة اختيارية على خدمة الفرع تمنع الموظف من
 * البيع بأعلى منها، وتترك السعر مفتوحاً حين تُترك فارغة.
 */
function cappedService(array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create(array_merge([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'max_selling_price' => 150,
        'is_tahazir' => false,
        'is_active' => true,
    ], $attrs));

    // BranchService is a Pivot (non-incrementing) — re-fetch for the id.
    return BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

/** A single-line DUE payload over the given service. */
function capPayload(BranchService $service, array $lineAttrs = []): array
{
    return [
        'status' => 'due',
        'lines' => [array_merge([
            'branch_service_id' => $service->id,
            'qty' => 1,
            'unit_price' => 100,
            'discount_pct' => 0,
        ], $lineAttrs)],
    ];
}

describe('Max selling price cap', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        // Branch admins are linked through branches.owner_id, not users.branch_id.
        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->actingAs($this->employee);
    });

    // ── الخدمات المسعّرة بالوحدة ────────────────────────────────────

    it('blocks an employee selling above the cap', function () {
        $service = cappedService();

        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 151]))
            ->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('allows an employee selling exactly at the cap', function () {
        $service = cappedService();

        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 150]))
            ->assertSessionHasNoErrors();

        expect((float) ServiceInvoice::firstOrFail()->lines->firstOrFail()->unit_price)->toBe(150.00);
    });

    it('leaves the price open when no cap is set', function () {
        $service = cappedService(['max_selling_price' => null]);

        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 9999]))
            ->assertSessionHasNoErrors();

        expect((float) ServiceInvoice::firstOrFail()->lines->firstOrFail()->unit_price)->toBe(9999.00);
    });

    it('reads a zero cap as open, not as a demand for free work', function () {
        $service = cappedService(['max_selling_price' => 0]);

        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 500]))
            ->assertSessionHasNoErrors();

        expect(ServiceInvoice::count())->toBe(1);
    });

    it('does not bind a branch admin', function () {
        $service = cappedService();

        $this->actingAs($this->branchAdmin)
            ->post(route('pos.service.store'), capPayload($service, ['unit_price' => 400]))
            ->assertSessionHasNoErrors();

        expect((float) ServiceInvoice::firstOrFail()->lines->firstOrFail()->unit_price)->toBe(400.00);
    });

    it('caps the line before any discount, so a discount cannot buy headroom', function () {
        $service = cappedService();

        // 200 مخصومةً 20% تنزل إلى 160، لكن السعر المكتوب هو ما يُقاس بالسقف.
        $this->post(route('pos.service.store'), capPayload($service, ['unit_price' => 200, 'discount_pct' => 20]))
            ->assertSessionHasErrors('lines');
    });

    // ── الخدمات المسعّرة بالمتر المربع ──────────────────────────────

    it('caps the meter price, not the total of the line', function () {
        $service = cappedService([
            'pricing_type' => 'sqm',
            'price_per_sqm' => 100,
            'max_selling_price' => 150,
        ]);

        // 2م × 1م = 2 م² بسعر متر 150 — عند السقف تماماً فيمرّ، مع أن إجمالي
        // السطر (300.00) ضعف السقف: السقف على المتر لا على المجموع.
        $this->post(route('pos.service.store'), capPayload($service, [
            'unit_price' => 150,
            'width_cm' => 200,
            'height_cm' => 100,
        ]))->assertSessionHasNoErrors();

        $line = ServiceInvoice::firstOrFail()->lines->firstOrFail();

        expect((float) $line->unit_price)->toBe(150.00)
            ->and((float) $line->subtotal)->toBe(300.00);
    });

    it('blocks a sqm line whose per-meter price exceeds the cap', function () {
        $service = cappedService([
            'pricing_type' => 'sqm',
            'price_per_sqm' => 100,
            'max_selling_price' => 150,
        ]);

        // 160 للمتر > 150.
        $this->post(route('pos.service.store'), capPayload($service, [
            'unit_price' => 160,
            'width_cm' => 200,
            'height_cm' => 100,
        ]))->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    // ── تعريف الخدمة ────────────────────────────────────────────────

    it('persists the cap from the branch service form and clears it when emptied', function () {
        $template = ServiceTemplate::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'max_selling_price' => 250.50,
            ])->assertSessionHasNoErrors();

        $service = BranchService::where('service_template_id', $template->id)->firstOrFail();
        expect((float) $service->max_selling_price)->toBe(250.50);

        $this->actingAs($this->branchAdmin)
            ->put(route('branch-services.update', $service->id), [
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'max_selling_price' => '',
            ])->assertSessionHasNoErrors();

        expect($service->fresh()->max_selling_price)->toBeNull();
    });

    it('rejects a negative cap', function () {
        $template = ServiceTemplate::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'max_selling_price' => -1,
            ])->assertSessionHasErrors('max_selling_price');
    });

    // ── تاسك 64: أرضية السعر على تعريف الخدمة ───────────────────────
    // الإلزام في نقطة البيع يخصّ التاسك 65؛ هنا الخانة وتحقّقها وحدهما.

    it('persists the floor from the branch service form and clears it when emptied', function () {
        $template = ServiceTemplate::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'min_selling_price' => 50.25,
            ])->assertSessionHasNoErrors();

        $service = BranchService::where('service_template_id', $template->id)->firstOrFail();
        expect((float) $service->min_selling_price)->toBe(50.25);

        $this->actingAs($this->branchAdmin)
            ->put(route('branch-services.update', $service->id), [
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'min_selling_price' => '',
            ])->assertSessionHasNoErrors();

        expect($service->fresh()->min_selling_price)->toBeNull();
    });

    it('rejects a floor above the cap — the service would be unsellable', function () {
        $template = ServiceTemplate::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'max_selling_price' => 100,
                'min_selling_price' => 150,
            ])->assertSessionHasErrors('min_selling_price');

        expect(BranchService::where('service_template_id', $template->id)->exists())->toBeFalse();
    });

    it('accepts a floor equal to the cap — one price exactly', function () {
        $template = ServiceTemplate::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'max_selling_price' => 100,
                'min_selling_price' => 100,
            ])->assertSessionHasNoErrors();
    });

    it('measures the floor against nothing when the cap is left open', function () {
        $template = ServiceTemplate::factory()->create();

        // بلا سقف لا معنى لمقارنة الأرضية به — ولا يُقرأ فراغه صفراً.
        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'max_selling_price' => '',
                'min_selling_price' => 900,
            ])->assertSessionHasNoErrors();

        expect((float) BranchService::where('service_template_id', $template->id)->firstOrFail()->min_selling_price)->toBe(900.00);
    });

    it('rejects a negative floor', function () {
        $template = ServiceTemplate::factory()->create();

        $this->actingAs($this->branchAdmin)
            ->post(route('branch-services.store'), [
                'service_template_id' => $template->id,
                'branch_id' => $this->branch->id,
                'base_commission_pct' => 10,
                'max_discount_pct' => 5,
                'min_selling_price' => -1,
            ])->assertSessionHasErrors('min_selling_price');
    });

    it('ships the floor to the POS beside the cap', function () {
        $service = cappedService(['min_selling_price' => 40]);

        $this->get(route('pos.service.create'))->assertInertia(
            fn ($page) => $page->where(
                'services',
                fn ($services) => collect($services)->firstWhere('id', $service->id)['minSellingPrice'] === 40,
            ),
        );
    });
});
