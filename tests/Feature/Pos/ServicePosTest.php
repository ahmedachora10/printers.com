<?php

use App\Enums\Roles;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\Coupon;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Notifications\DueInvoiceNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function makeBranchService(array $attrs = []): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create(array_merge([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'is_tahazir' => false,
        'is_active' => true,
    ], $attrs));

    // BranchService is a Pivot (non-incrementing), so create() doesn't hydrate
    // the id — re-fetch so callers get a model with its primary key populated.
    return BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

function svcPayload(array $overrides = []): array
{
    return array_merge([
        'status' => 'due',
        'lines' => [
            ['branch_service_id' => test()->service->id, 'qty' => 3, 'unit_price' => 10, 'discount_pct' => 0],
        ],
    ], $overrides);
}

describe('Service POS', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
        $this->actingAs($this->employee);

        // Branch admins are linked through branches.owner_id, not users.branch_id.
        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->service = makeBranchService();
    });

    it('lets an employee open the service POS screen', function () {
        $this->get(route('pos.service.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('pos/service/index'));
    });

    it('prevents an accountant from opening the service POS screen', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        $this->get(route('pos.service.create'))->assertForbidden();
    });

    it('creates a paid invoice with correct totals and commission', function () {
        $this->actingAs($this->branchAdmin);

        $this->post(route('pos.service.store'), svcPayload(['status' => 'paid']))
            ->assertRedirect(route('pos.service.create'))
            ->assertSessionHas('success');

        $invoice = ServiceInvoice::firstOrFail();

        expect($invoice->status->value)->toBe('paid')
            ->and($invoice->paid_at)->not->toBeNull()
            ->and((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->vat_amount)->toBe(4.50)
            ->and((float) $invoice->total_amount)->toBe(34.50)
            ->and((float) $invoice->employee_commission)->toBe(3.00)
            ->and($invoice->invoice_number)->toBe(sprintf('SINV-%03d-%05d', $this->branch->id, 1))
            ->and($invoice->lines)->toHaveCount(1);

        $line = $invoice->lines->first();
        expect((float) $line->commission_pct)->toBe(10.00)
            ->and((float) $line->commission_amount)->toBe(3.00);
    });

    it('writes one immutable commission ledger row per line', function () {
        $this->post(route('pos.service.store'), svcPayload());

        $invoice = ServiceInvoice::firstOrFail();
        $line = $invoice->lines->first();

        expect(CommissionLedger::count())->toBe(1);

        $entry = CommissionLedger::firstOrFail();
        expect($entry->user_id)->toBe($this->employee->id)
            ->and($entry->branch_id)->toBe($this->branch->id)
            ->and((float) $entry->amount)->toBe(3.00)
            ->and($entry->invoice_line_type)->toBe(ServiceInvoiceLine::class)
            ->and($entry->invoice_line_id)->toBe($line->id)
            ->and($entry->source_type->value)->toBe('standard')
            ->and($entry->is_tahazir)->toBeFalse()
            ->and($entry->paid_at)->toBeNull()
            ->and($entry->earned_at)->not->toBeNull();
    });

    it('flags tahazir commission on the ledger', function () {
        $tahazir = makeBranchService(['is_tahazir' => true, 'base_commission_pct' => 5]);

        $this->post(route('pos.service.store'), svcPayload([
            'lines' => [
                ['branch_service_id' => $tahazir->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 0],
            ],
        ]));

        $entry = CommissionLedger::firstOrFail();
        expect($entry->is_tahazir)->toBeTrue()
            ->and((float) $entry->amount)->toBe(5.00);
    });

    it('applies a line discount to the subtotal and commission', function () {
        $this->post(route('pos.service.store'), svcPayload([
            'lines' => [
                ['branch_service_id' => $this->service->id, 'qty' => 2, 'unit_price' => 10, 'discount_pct' => 10],
            ],
        ]));

        $invoice = ServiceInvoice::firstOrFail();
        // 2 * 10 * 0.9 = 18 subtotal; commission 10% = 1.80
        expect((float) $invoice->subtotal)->toBe(18.00)
            ->and((float) $invoice->employee_commission)->toBe(1.80);
    });

    it('rejects a discount that exceeds the service ceiling', function () {
        $this->post(route('pos.service.store'), svcPayload([
            'lines' => [
                ['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 100, 'discount_pct' => 50],
            ],
        ]))->assertSessionHasErrors('lines');

        expect(ServiceInvoice::count())->toBe(0)
            ->and(CommissionLedger::count())->toBe(0);
    });

    it('saves a due invoice with no paid_at', function () {
        $this->post(route('pos.service.store'), svcPayload(['status' => 'due']));

        $invoice = ServiceInvoice::firstOrFail();
        expect($invoice->status->value)->toBe('due')
            ->and($invoice->paid_at)->toBeNull();
    });

    it('forbids an employee from issuing a paid invoice', function () {
        $this->post(route('pos.service.store'), svcPayload(['status' => 'paid']))
            ->assertSessionHasErrors('status');

        expect(ServiceInvoice::count())->toBe(0)
            ->and(CommissionLedger::count())->toBe(0);
    });

    it('notifies the accountant and branch admin when an employee raises a due invoice', function () {
        Notification::fake();

        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        $this->post(route('pos.service.store'), svcPayload(['status' => 'due']))
            ->assertRedirect(route('pos.service.create'));

        Notification::assertSentTo([$accountant, $this->branchAdmin], DueInvoiceNotification::class);
        Notification::assertNotSentTo([$this->employee], DueInvoiceNotification::class);
    });

    it('computes VAT from the branch override rate', function () {
        $this->branch->update(['vat_rate_override' => 10.00]);

        $this->post(route('pos.service.store'), svcPayload());

        $invoice = ServiceInvoice::firstOrFail();
        expect((float) $invoice->vat_pct)->toBe(10.00)
            ->and((float) $invoice->vat_amount)->toBe(3.00)
            ->and((float) $invoice->total_amount)->toBe(33.00);
    });

    it('increments the invoice sequence per branch', function () {
        $this->post(route('pos.service.store'), svcPayload());
        $this->post(route('pos.service.store'), svcPayload());

        $numbers = ServiceInvoice::orderBy('id')->pluck('invoice_number')->all();
        expect($numbers)->toBe([
            sprintf('SINV-%03d-%05d', $this->branch->id, 1),
            sprintf('SINV-%03d-%05d', $this->branch->id, 2),
        ]);
    });

    it('requires at least one line', function () {
        $this->post(route('pos.service.store'), svcPayload(['lines' => []]))
            ->assertSessionHasErrors('lines');
    });

    it('creates a walk-in customer from name and phone', function () {
        $this->post(route('pos.service.store'), svcPayload([
            'walkin_name' => 'زائر الناسخ',
            'walkin_phone' => '0500000001',
        ]));

        $this->assertDatabaseHas('customers', [
            'full_name' => 'زائر الناسخ',
            'phone' => '0500000001',
            'branch_id' => $this->branch->id,
            'customer_type' => 'individual',
        ]);

        expect(ServiceInvoice::firstOrFail()->customer_id)->not->toBeNull();
    });

    it('applies a percentage coupon to the taxable base', function () {
        $coupon = Coupon::factory()->create([
            'branch_id' => $this->branch->id,
            'code' => 'save10',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'is_active' => true,
            'used_count' => 0,
        ]);

        $this->post(route('pos.service.store'), svcPayload(['coupon_code' => 'SAVE10']));

        $invoice = ServiceInvoice::firstOrFail();
        // subtotal 30, coupon 3, taxable 27, VAT 15% = 4.05, total 31.05
        expect((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->coupon_discount)->toBe(3.00)
            ->and((float) $invoice->total_amount)->toBe(31.05)
            ->and($invoice->coupon_id)->toBe($coupon->id);

        expect($coupon->refresh()->used_count)->toBe(1);
    });

    it('rejects an invalid coupon code', function () {
        $this->post(route('pos.service.store'), svcPayload(['coupon_code' => 'NOPE']))
            ->assertSessionHasErrors('coupon_code');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('applies an agent discount to the taxable base', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        $agent->agentProfile->update(['discount_mode' => 'discount', 'rate' => 10]);

        $this->post(route('pos.service.store'), svcPayload(['agent_id' => $agent->id]));

        $invoice = ServiceInvoice::firstOrFail();
        // subtotal 30, agent discount 10% = 3, taxable 27, VAT 15% = 4.05, total 31.05, no rebate
        expect((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->agent_discount)->toBe(3.00)
            ->and((float) $invoice->agent_rebate)->toBe(0.00)
            ->and((float) $invoice->total_amount)->toBe(31.05)
            ->and($invoice->agent_id)->toBe($agent->id);
    });

    it('records an agent rebate without deducting it from the total', function () {
        $agent = Agent::factory()->create(['branch_id' => $this->branch->id]);
        $agent->agentProfile->update(['discount_mode' => 'rebate', 'rate' => 10]);

        $this->post(route('pos.service.store'), svcPayload(['agent_id' => $agent->id]));

        $invoice = ServiceInvoice::firstOrFail();
        // subtotal 30, no discount, taxable 30, VAT 15% = 4.50, total 34.50, rebate 10% of total = 3.45
        expect((float) $invoice->subtotal)->toBe(30.00)
            ->and((float) $invoice->agent_discount)->toBe(0.00)
            ->and((float) $invoice->total_amount)->toBe(34.50)
            ->and((float) $invoice->agent_rebate)->toBe(3.45)
            ->and($invoice->agent_id)->toBe($agent->id);
    });

    it('rejects an agent from another branch', function () {
        $otherBranch = Branch::factory()->create();
        $agent = Agent::factory()->create(['branch_id' => $otherBranch->id]);

        $this->post(route('pos.service.store'), svcPayload(['agent_id' => $agent->id]))
            ->assertSessionHasErrors('agent_id');

        expect(ServiceInvoice::count())->toBe(0);
    });

    it('redirects to the print page when print is requested', function () {
        $this->post(route('pos.service.store'), svcPayload(['print' => true]));

        $invoice = ServiceInvoice::firstOrFail();

        $this->get(route('pos.service.print', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('pos/service/print'));
    });
});
