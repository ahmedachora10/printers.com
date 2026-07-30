<?php

use App\Enums\CustomerTypeEnum;
use App\Enums\LoyaltyTransactionTypeEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyTransaction;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\ServiceTemplate;
use App\Models\User;
use App\Models\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeEditService(): BranchService
{
    $template = ServiceTemplate::factory()->create();

    BranchService::create([
        'branch_id' => test()->branch->id,
        'service_template_id' => $template->id,
        'base_commission_pct' => 10,
        'max_discount_pct' => 20,
        'is_tahazir' => false,
        'is_active' => true,
    ]);

    return BranchService::where('branch_id', test()->branch->id)
        ->where('service_template_id', $template->id)
        ->firstOrFail();
}

/**
 * Create a DUE invoice owned by the acting employee through the real POS flow.
 */
function makeOwnedDueInvoice(array $lineOverrides = []): ServiceInvoice
{
    $line = array_merge(
        ['branch_service_id' => test()->service->id, 'qty' => 3, 'unit_price' => 10, 'discount_pct' => 0],
        $lineOverrides,
    );

    test()->post(route('pos.service.store'), ['status' => 'due', 'lines' => [$line]]);

    return ServiceInvoice::latest('id')->firstOrFail();
}

describe('Service invoice edit/delete', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create(['vat_rate_override' => 15.00]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch->update(['owner_id' => $this->branchAdmin->id]);

        $this->service = makeEditService();
        UserService::create([
            'user_id' => $this->employee->id,
            'branch_service_id' => $this->service->id,
            'commission_override_pct' => 10,
        ]);

        $this->actingAs($this->employee);
    });

    // ---- Edit -------------------------------------------------------------

    it('lets the owner employee open the edit screen for a due invoice', function () {
        $invoice = makeOwnedDueInvoice();

        $this->get(route('pos.service.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('pos/service/index')
                ->where('invoice.id', $invoice->id)
                ->where('invoice.invoiceNumber', $invoice->invoice_number)
                ->has('invoice.lines', 1));
    });

    it('updates a due invoice in place, keeping its number and recomputing commission', function () {
        $invoice = makeOwnedDueInvoice(); // subtotal 30, commission 3
        $number = $invoice->invoice_number;

        $this->put(route('pos.service.update', $invoice), [
            'lines' => [
                ['branch_service_id' => $this->service->id, 'qty' => 5, 'unit_price' => 10, 'discount_pct' => 0],
            ],
        ])->assertRedirect(route('invoices.show', ['type' => 'service', 'id' => $invoice->id]))
            ->assertSessionHas('success');

        $invoice->refresh();

        expect($invoice->invoice_number)->toBe($number)
            ->and($invoice->status->value)->toBe('due')
            ->and((float) $invoice->subtotal)->toBe(50.00)
            ->and((float) $invoice->employee_commission)->toBe(5.00)
            ->and((float) $invoice->total_amount)->toBe(57.50)
            ->and($invoice->lines()->count())->toBe(1);

        // A due invoice carries no ledger yet; approving it writes the recomputed
        // commission (5.00, not the original 3.00) to the immutable ledger.
        expect(CommissionLedger::count())->toBe(0);

        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', $invoice));

        expect((float) CommissionLedger::where('user_id', $this->employee->id)->sum('amount'))->toBe(5.00);
    });

    it('forbids editing a paid invoice', function () {
        $invoice = makeOwnedDueInvoice();
        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', $invoice));
        $this->actingAs($this->employee);

        $this->get(route('pos.service.edit', $invoice))->assertForbidden();
        $this->put(route('pos.service.update', $invoice), [
            'lines' => [['branch_service_id' => $this->service->id, 'qty' => 1, 'unit_price' => 10]],
        ])->assertForbidden();
    });

    it('forbids an employee from editing another employee\'s invoice', function () {
        $invoice = makeOwnedDueInvoice();

        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($other)->get(route('pos.service.edit', $invoice))->assertForbidden();
    });

    it('forbids an accountant from editing a service invoice', function () {
        $invoice = makeOwnedDueInvoice();

        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        $this->actingAs($accountant)->get(route('pos.service.edit', $invoice))->assertForbidden();
    });

    // ---- Delete -----------------------------------------------------------

    it('lets the owner soft-delete a due invoice with no commission to reverse', function () {
        $invoice = makeOwnedDueInvoice();

        $this->delete(route('pos.service.destroy', $invoice))
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHas('success');

        // A due invoice never earned commission, so the ledger stays empty.
        expect(ServiceInvoice::withTrashed()->find($invoice->id)->trashed())->toBeTrue()
            ->and(ServiceInvoice::find($invoice->id))->toBeNull()
            ->and((float) CommissionLedger::where('user_id', $this->employee->id)->sum('amount'))->toBe(0.00);
    });

    it('claws back earned points when deleting a paid invoice', function () {
        LoyaltyConfig::query()->create(['branch_id' => $this->branch->id, 'earning_rate' => 1, 'is_active' => true]);

        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_type' => CustomerTypeEnum::Individual,
            'points_balance' => 0,
            'cumulative_spend' => 0,
        ]);

        $invoice = makeOwnedDueInvoice(); // total 34.50
        $invoice->update(['customer_id' => $customer->id]);

        // Approve → earns floor(34.5 * 1) = 34 points, spend +34.50.
        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', $invoice));
        expect($customer->refresh()->points_balance)->toBe(34);

        $this->actingAs($this->employee)->delete(route('pos.service.destroy', $invoice))
            ->assertRedirect(route('invoices.index'));

        $customer->refresh();
        expect(ServiceInvoice::withTrashed()->find($invoice->id)->trashed())->toBeTrue()
            ->and($customer->points_balance)->toBe(0)
            ->and((float) $customer->cumulative_spend)->toBe(0.00)
            ->and((float) CommissionLedger::where('user_id', $this->employee->id)->sum('amount'))->toBe(0.00)
            ->and(LoyaltyTransaction::where('customer_id', $customer->id)
                ->where('type', LoyaltyTransactionTypeEnum::ManualAdjust)
                ->where('points', -34)
                ->exists())->toBeTrue();
    });

    it('blocks deleting a paid invoice that has a refund', function () {
        $invoice = makeOwnedDueInvoice();
        $this->actingAs($this->branchAdmin)->patch(route('invoices.service.pay', $invoice));

        Refund::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->branchAdmin->id,
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'invoice_type' => $invoice->getMorphClass(),
            'amount' => 10,
            'reason' => 'مرتجع جزئي',
        ]);

        $this->actingAs($this->employee)->delete(route('pos.service.destroy', $invoice))
            ->assertSessionHasErrors('invoice');

        expect(ServiceInvoice::find($invoice->id))->not->toBeNull();
    });

    it('forbids an accountant from deleting an employee invoice', function () {
        $invoice = makeOwnedDueInvoice();

        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        $this->actingAs($accountant)->delete(route('pos.service.destroy', $invoice))->assertForbidden();

        expect(ServiceInvoice::find($invoice->id))->not->toBeNull();
    });

    it('forbids deleting another employee\'s invoice', function () {
        $invoice = makeOwnedDueInvoice();

        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($other)->delete(route('pos.service.destroy', $invoice))->assertForbidden();
    });

    // ---- Customer tax number ----------------------------------------------

    it('lets the owner employee set the customer tax number of a due invoice', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'tax_number' => null]);
        $invoice = makeOwnedDueInvoice();
        $invoice->update(['customer_id' => $customer->id]);

        $this->patch(route('pos.service.tax-number', $invoice), ['tax_number' => '310000000000003'])
            ->assertRedirect()
            ->assertSessionHas('success');

        expect($customer->refresh()->tax_number)->toBe('310000000000003');
    });

    it('surfaces the customer tax number on the edit screen', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'tax_number' => '310000000000003']);
        $invoice = makeOwnedDueInvoice();
        $invoice->update(['customer_id' => $customer->id]);

        $this->get(route('pos.service.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoice.customer.taxNumber', '310000000000003'));
    });

    it('rejects a tax number that is not exactly 15 digits', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'tax_number' => null]);
        $invoice = makeOwnedDueInvoice();
        $invoice->update(['customer_id' => $customer->id]);

        $this->patch(route('pos.service.tax-number', $invoice), ['tax_number' => '3100000'])
            ->assertSessionHasErrors('tax_number');

        expect($customer->refresh()->tax_number)->toBeNull();
    });

    it('rejects a tax number update on an invoice with no customer', function () {
        $invoice = makeOwnedDueInvoice();

        $this->patch(route('pos.service.tax-number', $invoice), ['tax_number' => '310000000000003'])
            ->assertSessionHasErrors('tax_number');
    });

    it('forbids editing the tax number on another employee\'s invoice', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'tax_number' => null]);
        $invoice = makeOwnedDueInvoice();
        $invoice->update(['customer_id' => $customer->id]);

        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $other->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($other)
            ->patch(route('pos.service.tax-number', $invoice), ['tax_number' => '310000000000003'])
            ->assertForbidden();

        expect($customer->refresh()->tax_number)->toBeNull();
    });

    it('forbids editing the tax number once the invoice is paid', function () {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id, 'tax_number' => null]);
        $invoice = makeOwnedDueInvoice();
        $invoice->update(['customer_id' => $customer->id, 'status' => 'paid', 'paid_at' => now()]);

        $this->patch(route('pos.service.tax-number', $invoice), ['tax_number' => '310000000000003'])
            ->assertForbidden();
    });

    // ---- Line notes --------------------------------------------------------

    it('stores the free-text detail typed against a service line', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 1,
                'unit_price' => 10,
                'discount_pct' => 0,
                'notes' => '  ورق مقوّى 300 جرام  ',
            ]],
        ])->assertRedirect();

        $line = ServiceInvoice::latest('id')->firstOrFail()->lines()->firstOrFail();

        expect($line->notes)->toBe('ورق مقوّى 300 جرام');
    });

    it('collapses a blank detail box to null', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 1,
                'unit_price' => 10,
                'discount_pct' => 0,
                'notes' => '   ',
            ]],
        ])->assertRedirect();

        expect(ServiceInvoice::latest('id')->firstOrFail()->lines()->firstOrFail()->notes)->toBeNull();
    });

    it('rejects a detail longer than 500 characters', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 1,
                'unit_price' => 10,
                'discount_pct' => 0,
                'notes' => str_repeat('ا', 501),
            ]],
        ])->assertSessionHasErrors('lines.0.notes');
    });

    it('keeps the line detail when the invoice is re-edited', function () {
        $invoice = makeOwnedDueInvoice();

        $this->put(route('pos.service.update', $invoice), [
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 2,
                'unit_price' => 10,
                'discount_pct' => 0,
                'notes' => 'تسليم الخميس',
            ]],
        ])->assertRedirect();

        expect($invoice->refresh()->lines()->firstOrFail()->notes)->toBe('تسليم الخميس');
    });

    it('surfaces the line detail on the edit screen', function () {
        $this->post(route('pos.service.store'), [
            'status' => 'due',
            'lines' => [[
                'branch_service_id' => $this->service->id,
                'qty' => 1,
                'unit_price' => 10,
                'discount_pct' => 0,
                'notes' => 'تسليم الخميس',
            ]],
        ]);

        $invoice = ServiceInvoice::latest('id')->firstOrFail();

        $this->get(route('pos.service.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoice.lines.0.notes', 'تسليم الخميس'));
    });
});
