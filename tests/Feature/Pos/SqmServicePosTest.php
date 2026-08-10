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
 * A branch service priced by the square meter: the POS derives the per-piece
 * price from width × height (cm) at price_per_sqm, server-side.
 */
function makeSqmService(array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create(array_merge([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'pricing_type' => 'sqm',
        'price_per_sqm' => 100,
        'agent_commission_per_sqm' => 5,
        'is_tahazir' => false,
        'is_active' => true,
    ], $attrs));

    // BranchService is a Pivot (non-incrementing) — re-fetch for the id.
    return BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

describe('Square-meter priced services', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($this->employee);

        $this->sqmService = makeSqmService();
    });

    it('derives the unit price from the dimensions, ignoring the client price', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->sqmService->id,
                'qty' => 2,
                // A spoofed client price must never be trusted for a sqm service.
                'unit_price' => 999,
                'discount_pct' => 0,
                'width_cm' => 100,
                'height_cm' => 70,
            ]],
        ])->assertRedirect(route('pos.service.create'));

        $invoice = ServiceInvoice::firstOrFail();
        $line = $invoice->lines->firstOrFail();

        // 1.00m × 0.70m = 0.7 م² × 100 = 70.00 per piece; qty 2 → 140.00
        expect((float) $line->unit_price)->toBe(70.00)
            ->and((float) $line->width_cm)->toBe(100.00)
            ->and((float) $line->height_cm)->toBe(70.00)
            ->and((float) $line->subtotal)->toBe(140.00)
            // السعر شامل الضريبة: 140 يدفعها العميل، صافيها 121.74 وضريبتها 18.26
            ->and((float) $invoice->subtotal)->toBe(140.00)
            ->and((float) $invoice->vat_amount)->toBe(18.26)
            ->and((float) $invoice->total_amount)->toBe(140.00);
    });

    it('rejects a sqm line without dimensions', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->sqmService->id,
                'qty' => 1,
                'unit_price' => 0,
                'discount_pct' => 0,
            ]],
        ])->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('applies the line discount to the derived price', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->sqmService->id,
                'qty' => 1,
                'unit_price' => 0,
                'discount_pct' => 20,
                'width_cm' => 50,
                'height_cm' => 50,
            ]],
        ]);

        // 0.5 × 0.5 = 0.25 م² × 100 = 25.00; 20% discount → 20.00
        $invoice = ServiceInvoice::firstOrFail();
        expect((float) $invoice->lines->first()->unit_price)->toBe(25.00)
            ->and((float) $invoice->subtotal)->toBe(20.00);
    });

    it('still enforces the max discount ceiling on a sqm line', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->sqmService->id,
                'qty' => 1,
                'unit_price' => 0,
                'discount_pct' => 50,
                'width_cm' => 100,
                'height_cm' => 100,
            ]],
        ])->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('ignores dimensions sent for a unit-priced service', function () {
        $unitTemplate = ServiceTemplate::factory()->create();
        BranchService::create([
            'branch_id' => $this->branch->id,
            'service_template_id' => $unitTemplate->id,
            'base_commission_pct' => 10,
            'max_discount_pct' => 0,
            'is_tahazir' => false,
            'is_active' => true,
        ]);
        $unitService = BranchService::where('service_template_id', $unitTemplate->id)->firstOrFail();

        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $unitService->id,
                'qty' => 1,
                'unit_price' => 40,
                'discount_pct' => 0,
                'width_cm' => 100,
                'height_cm' => 70,
            ]],
        ]);

        $line = ServiceInvoice::firstOrFail()->lines->firstOrFail();
        // The client price stands and no dimensions are stored on a unit line.
        expect((float) $line->unit_price)->toBe(40.00)
            ->and($line->width_cm)->toBeNull()
            ->and($line->height_cm)->toBeNull();
    });
});
