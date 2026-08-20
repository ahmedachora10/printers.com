<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\ProductUnit;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Build a paid product invoice with a single stocked product line.
 */
function refundableProductInvoice(Branch $branch, User $user, int $qty = 5, float $unitPrice = 20, int $openingStock = 10): array
{
    $category = ProductCategory::factory()->create();
    $unit = ProductUnit::factory()->create();
    $product = Product::factory()->create([
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'cost_price' => 8,
        'current_stock' => 0,
    ]);

    StockMovement::factory()->create([
        'product_id' => $product->id,
        'branch_id' => $branch->id,
        'type' => 'opening_stock',
        'qty' => $openingStock,
    ]);
    $product->refresh();

    $subtotal = $qty * $unitPrice;
    $invoice = ProductInvoice::create([
        'invoice_number' => 'INV-001-00001',
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'subtotal' => $subtotal,
        'vat_pct' => 15,
        'vat_amount' => round($subtotal * 0.15, 2),
        'total_amount' => round($subtotal * 1.15, 2),
        'status' => InvoiceStatusEnum::PAID,
        'paid_at' => now(),
    ]);
    $invoice->lines()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'sku' => $product->sku,
        'qty' => $qty,
        'unit_price' => $unitPrice,
        'discount_pct' => 0,
        'subtotal' => $subtotal,
    ]);

    return [$invoice->fresh(), $product];
}

/**
 * Build a paid service invoice with one line and an unpaid commission row.
 */
function refundableServiceInvoice(Branch $branch, User $employee, float $commission = 100): ServiceInvoice
{
    $invoice = ServiceInvoice::create([
        'invoice_number' => 'SINV-001-00001',
        'branch_id' => $branch->id,
        'user_id' => $employee->id,
        'subtotal' => 1000,
        'vat_pct' => 15,
        'vat_amount' => 150,
        'total_amount' => 1150,
        'employee_commission' => $commission,
        'status' => InvoiceStatusEnum::PAID,
        'paid_at' => now(),
    ]);

    /** @var ServiceInvoiceLine $line */
    $line = $invoice->lines()->create([
        'branch_service_id' => null,
        'service_name' => 'طباعة',
        'qty' => 1,
        'unit_price' => 1000,
        'discount_pct' => 0,
        'subtotal' => 1000,
        'commission_pct' => 10,
        'commission_amount' => $commission,
    ]);

    CommissionLedger::create([
        'user_id' => $employee->id,
        'branch_id' => $branch->id,
        'invoice_line_id' => $line->id,
        'invoice_line_type' => ServiceInvoiceLine::class,
        'amount' => $commission,
        'is_tahazir' => false,
        'earned_at' => now(),
    ]);

    return $invoice->fresh();
}

describe('Refunds', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);

        $this->actingAs($this->admin);
    });

    // ── AUTHORIZATION ──────────────────────────────────────────────

    it('shows the refunds page to branch-admin', function () {
        $this->get(route('refunds.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('refunds/index'));
    });

    it('forbids an employee from viewing or creating refunds', function () {
        [$invoice] = refundableProductInvoice($this->branch, $this->admin);

        $this->actingAs($this->employee)
            ->get(route('refunds.index'))->assertForbidden();

        $this->actingAs($this->employee)
            ->post(route('refunds.store'), [
                'source_type' => 'product',
                'invoice_id' => $invoice->id,
                'amount' => 10,
                'reason' => 'test',
            ])->assertForbidden();
    });

    // ── المحاسب والمرتجع بعد الاعتماد (تاسك 42) ────────────────────

    it('forbids the accountant from refunding an approved invoice', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        // refundableProductInvoice تُنشئ فاتورة معتمدة (paid).
        [$invoice] = refundableProductInvoice($this->branch, $this->admin);

        $this->actingAs($accountant)
            ->post(route('refunds.store'), [
                'source_type' => 'product',
                'invoice_id' => $invoice->id,
                'amount' => 10,
                'reason' => 'بعد الاعتماد',
            ])->assertForbidden();

        expect(Refund::where('invoice_id', $invoice->id)->count())->toBe(0);
    });

    it('hides the refund button from the accountant on an approved invoice', function () {
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        [$invoice] = refundableProductInvoice($this->branch, $this->admin);

        $this->actingAs($accountant)
            ->get(route('invoices.show', ['type' => 'product', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoice.canRefund', false));
    });

    it('still lets the branch admin refund an approved invoice', function () {
        [$invoice] = refundableProductInvoice($this->branch, $this->admin);

        $this->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => 10,
            'reason' => 'قرار إداري',
        ])->assertRedirect();

        expect(Refund::where('invoice_id', $invoice->id)->count())->toBe(1);
    });

    it('keeps the refunds screen open to the accountant', function () {
        // المنع على إنشاء المرتجع لفاتورة معتمدة، لا على الشاشة والتقارير.
        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);

        $this->actingAs($accountant)->get(route('refunds.index'))->assertOk();
    });

    // ── PRODUCT REFUND + STOCK ─────────────────────────────────────

    it('records a product refund and returns stock when requested', function () {
        [$invoice, $product] = refundableProductInvoice($this->branch, $this->admin, qty: 5, openingStock: 10);

        expect($product->current_stock)->toEqual(10);

        $this->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total_amount,
            'reason' => 'منتج تالف',
            'reverse_stock' => true,
        ])->assertRedirect(route('refunds.index'));

        $this->assertDatabaseHas('refunds', [
            'invoice_id' => $invoice->id,
            'source_type' => 'product',
            'stock_reversed' => true,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'return_in',
            'qty' => 5,
            'reference_type' => Refund::class,
        ]);

        expect($product->fresh()->current_stock)->toEqual(15);
    });

    it('does not move stock when reverse_stock is not requested', function () {
        [$invoice, $product] = refundableProductInvoice($this->branch, $this->admin, qty: 5, openingStock: 10);

        $this->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => 10,
            'reason' => 'استرداد جزئي',
        ])->assertRedirect();

        expect(StockMovement::where('type', 'return_in')->count())->toBe(0);
        expect($product->fresh()->current_stock)->toEqual(10);
    });

    // ── SERVICE REFUND + COMMISSION ────────────────────────────────

    it('reverses unpaid commission proportionally on a service refund', function () {
        $invoice = refundableServiceInvoice($this->branch, $this->employee, commission: 100);

        // Refund half the invoice total -> reverse half the commission.
        $this->post(route('refunds.store'), [
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'amount' => 575, // half of 1150
            'reason' => 'إلغاء الخدمة',
        ])->assertRedirect();

        $reversal = CommissionLedger::where('user_id', $this->employee->id)
            ->where('amount', '<', 0)
            ->first();

        expect($reversal)->not->toBeNull();
        expect((float) $reversal->amount)->toBe(-50.0);

        // Net unpaid commission for the employee is now 50.
        $net = (float) CommissionLedger::where('user_id', $this->employee->id)
            ->whereNull('paid_at')
            ->sum('amount');
        expect($net)->toBe(50.0);
    });

    it('leaves already-paid commission untouched', function () {
        $invoice = refundableServiceInvoice($this->branch, $this->employee, commission: 100);
        CommissionLedger::where('user_id', $this->employee->id)->update(['paid_at' => now()]);

        $this->post(route('refunds.store'), [
            'source_type' => 'service',
            'invoice_id' => $invoice->id,
            'amount' => 1150,
            'reason' => 'مرتجع كامل',
        ])->assertRedirect();

        expect(CommissionLedger::where('amount', '<', 0)->count())->toBe(0);
    });

    // ── GUARDS ─────────────────────────────────────────────────────

    it('rejects refunding more than the refundable amount', function () {
        [$invoice] = refundableProductInvoice($this->branch, $this->admin);

        $this->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => (float) $invoice->total_amount + 100,
            'reason' => 'مبالغة',
        ])->assertSessionHasErrors('amount');

        expect(Refund::count())->toBe(0);
    });

    it('prevents over-refund across multiple partial refunds', function () {
        [$invoice] = refundableProductInvoice($this->branch, $this->admin, qty: 5, unitPrice: 20); // total 115

        $this->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'reason' => 'جزئي أول',
        ])->assertRedirect();

        $this->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => 50,
            'reason' => 'جزئي ثانٍ',
        ])->assertSessionHasErrors('amount');

        expect(Refund::count())->toBe(1);
    });

    it('rejects refunding a cancelled invoice', function () {
        [$invoice] = refundableProductInvoice($this->branch, $this->admin);
        $invoice->update(['status' => InvoiceStatusEnum::CANCELLED]);

        $this->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => 10,
            'reason' => 'ملغاة',
        ])->assertSessionHasErrors('invoice_id');
    });

    it('blocks reversing stock twice for the same invoice', function () {
        [$invoice, $product] = refundableProductInvoice($this->branch, $this->admin, qty: 2, unitPrice: 10, openingStock: 10); // total 23

        $this->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => 10,
            'reason' => 'أول',
            'reverse_stock' => true,
        ])->assertRedirect();

        $this->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => 5,
            'reason' => 'ثانٍ',
            'reverse_stock' => true,
        ])->assertSessionHasErrors('reverse_stock');

        expect(StockMovement::where('type', 'return_in')->count())->toBe(1);
    });

    // ── LOOKUP ─────────────────────────────────────────────────────

    it('looks up an invoice by number with refundable summary', function () {
        [$invoice] = refundableProductInvoice($this->branch, $this->admin, qty: 5, unitPrice: 20); // total 115

        $this->getJson(route('refunds.lookup', ['number' => $invoice->invoice_number]))
            ->assertOk()
            ->assertJson([
                'found' => true,
                'invoice' => [
                    'type' => 'product',
                    'totalAmount' => 115,
                    'alreadyRefunded' => 0,
                    'refundable' => 115,
                    'hasProducts' => true,
                ],
            ]);
    });

    it('returns not-found for an unknown invoice number', function () {
        $this->getJson(route('refunds.lookup', ['number' => 'INV-999-99999']))
            ->assertOk()
            ->assertJson(['found' => false]);
    });

    // ── INVOICE DETAILS PAGE ───────────────────────────────────────

    it('surfaces refunds and refunded totals on the invoice details page', function () {
        [$invoice] = refundableProductInvoice($this->branch, $this->admin, qty: 5, unitPrice: 20); // total 115

        $this->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => 50,
            'reason' => 'استرداد جزئي',
        ])->assertRedirect();

        $this->get(route('invoices.show', ['type' => 'product', 'id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.refundedTotal', 50)
                ->where('invoice.refundableRemaining', 65)
                ->where('invoice.isFullyRefunded', false)
                ->where('invoice.canRefund', true)
                ->has('invoice.refunds', 1)
                ->where('invoice.refunds.0.amount', 50));
    });

    it('marks an invoice fully refunded and disables further refunds', function () {
        [$invoice] = refundableProductInvoice($this->branch, $this->admin, qty: 5, unitPrice: 20); // total 115

        $this->post(route('refunds.store'), [
            'source_type' => 'product',
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total_amount,
            'reason' => 'مرتجع كامل',
        ])->assertRedirect();

        $this->get(route('invoices.show', ['type' => 'product', 'id' => $invoice->id]))
            ->assertInertia(fn ($page) => $page
                ->where('invoice.isFullyRefunded', true)
                ->where('invoice.refundableRemaining', 0)
                ->where('invoice.canRefund', false));
    });
});
