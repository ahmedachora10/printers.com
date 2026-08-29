<?php

use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInvoice;
use App\Models\ProductUnit;
use App\Models\ServiceInvoiceLine;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

function stockedProduct(Branch $branch, int $minLevel, int $opening): Product
{
    $product = Product::factory()->create([
        'branch_id' => $branch->id,
        'category_id' => ProductCategory::factory()->create()->id,
        'unit_id' => ProductUnit::factory()->create()->id,
        'min_stock_level' => $minLevel,
        'current_stock' => 0,
    ]);

    StockMovement::factory()->create([
        'product_id' => $product->id,
        'branch_id' => $branch->id,
        'type' => StockMovementTypeEnum::OPENING_STOCK,
        'qty' => $opening,
    ]);

    return $product->refresh();
}

describe('Notification triggers', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);
        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id, 'vat_rate_override' => 15]);
        $this->admin->update(['branch_id' => $this->branch->id]);

        $this->accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant->addRole(Roles::ACCOUNTANT->value);

        $this->employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->employee->addRole(Roles::EMPLOYEE->value);
    });

    it('notifies branch-admin and accountant when a sale crosses below min stock', function () {
        $product = stockedProduct($this->branch, minLevel: 5, opening: 10);

        app(RecordStockMovementAction::class)->handle(
            $product,
            StockMovementTypeEnum::SALE_OUT,
            6, // 10 -> 4, crosses below 5
            ['created_by' => $this->admin->id],
        );

        expect($this->admin->fresh()->notifications()->count())->toBe(1)
            ->and($this->accountant->fresh()->notifications()->count())->toBe(1)
            ->and($this->employee->fresh()->notifications()->count())->toBe(0);

        expect($this->admin->fresh()->notifications()->first()->data['type'])->toBe('low_stock');
    });

    it('does not notify when a sale leaves stock above min', function () {
        $product = stockedProduct($this->branch, minLevel: 5, opening: 10);

        app(RecordStockMovementAction::class)->handle(
            $product,
            StockMovementTypeEnum::SALE_OUT,
            2, // 10 -> 8, still above 5
            ['created_by' => $this->admin->id],
        );

        expect($this->admin->fresh()->notifications()->count())->toBe(0)
            ->and($this->accountant->fresh()->notifications()->count())->toBe(0);
    });

    it('does not re-notify on a subsequent sale once already below min', function () {
        $product = stockedProduct($this->branch, minLevel: 5, opening: 10);
        $action = app(RecordStockMovementAction::class);

        $action->handle($product, StockMovementTypeEnum::SALE_OUT, 6, ['created_by' => $this->admin->id]); // crosses
        $action->handle($product->fresh(), StockMovementTypeEnum::SALE_OUT, 1, ['created_by' => $this->admin->id]); // already low

        expect($this->admin->fresh()->notifications()->count())->toBe(1);
    });

    it('notifies branch-admin and accountant when a due product invoice is created', function () {
        $this->actingAs($this->admin)
            ->post(route('pos.product.store'), [
                'payment_method_id' => paymentMethodId(),
                'status' => InvoiceStatusEnum::DUE->value,
                'lines' => [['name' => 'طباعة يدوية', 'qty' => 1, 'unit_price' => 100]],
            ])->assertRedirect();

        expect($this->admin->fresh()->notifications()->count())->toBe(1)
            ->and($this->accountant->fresh()->notifications()->count())->toBe(1);

        expect($this->admin->fresh()->notifications()->first()->data['type'])->toBe('due_invoice');
    });

    it('does not notify when a paid product invoice is created', function () {
        $this->actingAs($this->admin)
            ->post(route('pos.product.store'), [
                'payment_method_id' => paymentMethodId(),
                'status' => InvoiceStatusEnum::PAID->value,
                'lines' => [['name' => 'طباعة يدوية', 'qty' => 1, 'unit_price' => 100]],
            ])->assertRedirect();

        expect($this->admin->fresh()->notifications()->count())->toBe(0);
    });

    it('notifies the employee when their commission is paid', function () {
        $invoice = ProductInvoice::create([
            'invoice_number' => 'INV-001-00001',
            'branch_id' => $this->branch->id,
            'user_id' => $this->employee->id,
            'subtotal' => 1000,
            'vat_pct' => 15,
            'vat_amount' => 150,
            'total_amount' => 1150,
            'status' => InvoiceStatusEnum::PAID,
            'paid_at' => now(),
        ]);
        $line = $invoice->lines()->create([
            'product_name' => 'x', 'qty' => 1, 'unit_price' => 1000, 'discount_pct' => 0, 'subtotal' => 1000,
        ]);

        CommissionLedger::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'invoice_line_id' => $line->id,
            'invoice_line_type' => ServiceInvoiceLine::class,
            'amount' => 100,
            'is_tahazir' => false,
            'earned_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('commissions.pay'), [
                'user_id' => $this->employee->id,
                'period_start' => now()->subDay()->toDateString(),
                'period_end' => now()->addDay()->toDateString(),
            ])->assertRedirect();

        expect($this->employee->fresh()->notifications()->count())->toBe(1);
        expect($this->employee->fresh()->notifications()->first()->data['type'])->toBe('commission_paid');
    });

    it('notifies the branch-admin when a refund is processed', function () {
        $invoice = ProductInvoice::create([
            'invoice_number' => 'INV-001-00002',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'subtotal' => 100,
            'vat_pct' => 15,
            'vat_amount' => 15,
            'total_amount' => 115,
            'status' => InvoiceStatusEnum::PAID,
            'paid_at' => now(),
        ]);

        // مدير الفرع هو من يسجّل المرتجع على فاتورة معتمدة — المحاسب مُنع من ذلك
        // في تاسك 42. المقصود هنا هو الإشعار لا الصلاحية.
        $this->actingAs($this->admin)
            ->post(route('refunds.store'), [
                'source_type' => 'product',
                'invoice_id' => $invoice->id,
                'amount' => 50,
                'reason' => 'استرداد جزئي',
            ])->assertRedirect();

        expect($this->admin->fresh()->notifications()->count())->toBe(1);
        expect($this->admin->fresh()->notifications()->first()->data['type'])->toBe('refund');
    });
});
