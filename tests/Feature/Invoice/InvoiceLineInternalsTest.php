<?php

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\ProductInvoice;
use App\Models\ProductInvoiceLine;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * تاسك 94 — تكلفة الخامات وعمولة السطر والشريحة كانت مكتوبة في القاعدة منذ
 * تاسك 7 ولا تخرج من InvoiceLineResource، فلم تعرضها شاشة قط. الآن تخرج
 * للمراجعين ولصاحب الفاتورة وحدهم، ولا تُطبع لأحد.
 */
function invoiceWithMaterials(int $branchId, int $userId, ?int $paymentMethodId = null): ServiceInvoice
{
    $invoice = ServiceInvoice::create([
        'invoice_number' => 'SINV-MAT-'.fake()->unique()->numberBetween(1, 999999),
        'branch_id' => $branchId,
        'user_id' => $userId,
        'payment_method_id' => $paymentMethodId,
        'subtotal' => 300,
        'vat_pct' => 15,
        'vat_amount' => 39.13,
        'total_amount' => 300,
        'employee_commission' => 24,
        'status' => InvoiceStatusEnum::PAID,
        'paid_at' => now(),
    ]);

    ServiceInvoiceLine::create([
        'invoice_id' => $invoice->id,
        'service_name' => 'اكريليك',
        'qty' => 3,
        'unit_price' => 100,
        'discount_pct' => 0,
        'subtotal' => 300,
        'commission_pct' => 8,
        'commission_amount' => 24,
        'materials_cost' => 12,
        'materials_total' => 36,
        'tier_applied' => 2,
    ]);

    return $invoice;
}

describe('Internal line costs on the invoice viewer', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branchAdmin = User::factory()->create();
        $this->branchAdmin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->branchAdmin->id]);
        $this->branchAdmin->update(['branch_id' => $this->branch->id]);

        $this->owner = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner->addRole(Roles::EMPLOYEE->value);

        $this->otherEmployee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->otherEmployee->addRole(Roles::EMPLOYEE->value);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->method = PaymentMethod::factory()->create(['name' => 'نقد']);
        $this->invoice = invoiceWithMaterials($this->branch->id, $this->owner->id, $this->method->id);
    });

    it('shows the accountant the materials cost, the line commission and the tier', function () {
        $this->actingAs($this->accountant)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]))
            ->assertInertia(fn ($page) => $page
                ->where('invoice.lines.0.materialsCost', 12)
                ->where('invoice.lines.0.materialsTotal', 36)
                ->where('invoice.lines.0.commissionAmount', 24)
                ->where('invoice.lines.0.tierApplied', 2));
    });

    it('shows the owning employee the cost that is deducted from their own commission base', function () {
        $this->actingAs($this->owner)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]))
            ->assertInertia(fn ($page) => $page
                ->where('invoice.lines.0.materialsTotal', 36)
                ->where('invoice.lines.0.commissionAmount', 24));
    });

    it('withholds it from an employee whose invoice it is not', function () {
        // ServiceInvoicePolicy::view تسمح لكل موظفي الفرع بفتح الفاتورة —
        // فالحجب هنا هو الفارق الوحيد بين صاحبها وغيره.
        $this->actingAs($this->otherEmployee)
            ->get(route('invoices.show', ['type' => 'service', 'id' => $this->invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.lines.0.materialsCost', null)
                ->where('invoice.lines.0.materialsTotal', null)
                ->where('invoice.lines.0.commissionAmount', null)
                ->where('invoice.lines.0.tierApplied', null));
    });

    it('never sends the cost to a print sheet, for any role', function () {
        foreach ([$this->branchAdmin, $this->accountant, $this->owner] as $user) {
            $this->actingAs($user)
                ->get(route('invoices.print', ['type' => 'service', 'id' => $this->invoice->id]))
                ->assertInertia(fn ($page) => $page
                    ->where('invoice.lines.0.materialsCost', null)
                    ->where('invoice.lines.0.materialsTotal', null)
                    ->where('invoice.lines.0.commissionAmount', null)
                    ->where('invoice.lines.0.tierApplied', null)
                    // ولا تُمسّ بقية بيانات السطر.
                    ->where('invoice.lines.0.name', 'اكريليك')
                    ->where('invoice.lines.0.subtotal', 300));
        }
    });

    it('leaves the materials figures null on a product line', function () {
        $product = ProductInvoice::create([
            'invoice_number' => 'INV-MAT-001',
            'branch_id' => $this->branch->id,
            'user_id' => $this->owner->id,
            'payment_method_id' => $this->method->id,
            'subtotal' => 50,
            'vat_pct' => 15,
            'vat_amount' => 6.52,
            'total_amount' => 50,
            'status' => InvoiceStatusEnum::PAID,
            'paid_at' => now(),
        ]);

        ProductInvoiceLine::create([
            'invoice_id' => $product->id,
            'product_name' => 'ورق',
            'qty' => 1,
            'unit_price' => 50,
            'discount_pct' => 0,
            'subtotal' => 50,
        ]);

        // سطور المنتجات لا تحمل خامات ولا شريحة — الحقول موجودة في الشكل
        // الموحَّد وقيمتها null، فلا تتفرّع الواجهة على النوع.
        $this->actingAs($this->accountant)
            ->get(route('invoices.show', ['type' => 'product', 'id' => $product->id]))
            ->assertInertia(fn ($page) => $page
                ->where('invoice.lines.0.materialsCost', null)
                ->where('invoice.lines.0.materialsTotal', null)
                ->where('invoice.lines.0.tierApplied', null));
    });
});
