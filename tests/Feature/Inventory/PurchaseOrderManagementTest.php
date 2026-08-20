<?php

use App\Enums\PurchaseOrderStatusEnum;
use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Purchase order management', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);
        $this->actingAs($this->admin);

        $this->category = ProductCategory::factory()->create();
        $this->unit = ProductUnit::factory()->create();
        $this->supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);

        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'current_stock' => 0,
        ]);
    });

    $makePo = function (?array $lines = null): PurchaseOrder {
        $lines ??= [['product_id' => test()->product->id, 'ordered_qty' => 10, 'unit_cost' => 5]];

        test()->post(route('inventory.purchase-orders.store'), [
            'supplier_id' => test()->supplier->id,
            'order_date' => now()->format('Y-m-d'),
            'lines' => $lines,
        ])->assertRedirect();

        return PurchaseOrder::latest('id')->first();
    };

    it('creates a purchase order with a branch-sequenced number and computed subtotals', function () use ($makePo) {
        $po = $makePo([
            ['product_id' => $this->product->id, 'ordered_qty' => 4, 'unit_cost' => 25],
        ]);

        expect($po->po_number)->toBe(sprintf('PO-%03d-%05d', $this->branch->id, 1));
        expect($po->status)->toBe(PurchaseOrderStatusEnum::DRAFT);
        expect((float) $po->lines->first()->subtotal)->toBe(100.0);
    });

    it('allows editing a draft but blocks editing once sent', function () use ($makePo) {
        $po = $makePo();

        $this->put(route('inventory.purchase-orders.update', $po), [
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->format('Y-m-d'),
            'lines' => [['product_id' => $this->product->id, 'ordered_qty' => 20, 'unit_cost' => 5]],
        ])->assertRedirect();

        expect($po->refresh()->lines->first()->ordered_qty)->toEqual(20);

        $po->update(['status' => PurchaseOrderStatusEnum::SENT]);

        $this->put(route('inventory.purchase-orders.update', $po), [
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->format('Y-m-d'),
            'lines' => [['product_id' => $this->product->id, 'ordered_qty' => 99, 'unit_cost' => 5]],
        ])->assertSessionHasErrors('status');
    });

    it('marks a draft as sent', function () use ($makePo) {
        $po = $makePo();

        $this->patch(route('inventory.purchase-orders.sent', $po))->assertRedirect();

        expect($po->refresh()->status)->toBe(PurchaseOrderStatusEnum::SENT);
    });

    it('records a partial receipt as a purchase_in movement and sets status to partial', function () use ($makePo) {
        $po = $makePo([['product_id' => $this->product->id, 'ordered_qty' => 10, 'unit_cost' => 5]]);
        $po->update(['status' => PurchaseOrderStatusEnum::SENT]);
        $line = $po->lines->first();

        $this->post(route('inventory.purchase-orders.receive', $po), [
            'receipts' => [['line_id' => $line->id, 'qty' => 4]],
        ])->assertRedirect();

        expect($po->refresh()->status)->toBe(PurchaseOrderStatusEnum::PARTIAL);
        expect($line->refresh()->received_qty)->toEqual(4);
        expect($this->product->refresh()->current_stock)->toEqual(4);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'type' => StockMovementTypeEnum::PURCHASE_IN->value,
            'qty' => 4,
            'reference_type' => PurchaseOrder::class,
            'reference_id' => $po->id,
        ]);
    });

    it('marks the order received once every line is fully delivered', function () use ($makePo) {
        $po = $makePo([['product_id' => $this->product->id, 'ordered_qty' => 10, 'unit_cost' => 5]]);
        $po->update(['status' => PurchaseOrderStatusEnum::SENT]);
        $line = $po->lines->first();

        $this->post(route('inventory.purchase-orders.receive', $po), [
            'receipts' => [['line_id' => $line->id, 'qty' => 6]],
        ])->assertRedirect();
        $this->post(route('inventory.purchase-orders.receive', $po), [
            'receipts' => [['line_id' => $line->id, 'qty' => 4]],
        ])->assertRedirect();

        expect($po->refresh()->status)->toBe(PurchaseOrderStatusEnum::RECEIVED);
        expect($this->product->refresh()->current_stock)->toEqual(10);
    });

    it('rejects receiving more than the remaining quantity', function () use ($makePo) {
        $po = $makePo([['product_id' => $this->product->id, 'ordered_qty' => 10, 'unit_cost' => 5]]);
        $po->update(['status' => PurchaseOrderStatusEnum::SENT]);
        $line = $po->lines->first();

        $this->post(route('inventory.purchase-orders.receive', $po), [
            'receipts' => [['line_id' => $line->id, 'qty' => 11]],
        ])->assertSessionHasErrors('receipts.0.qty');

        expect($this->product->refresh()->current_stock)->toEqual(0);
    });

    it('cancels a sent order but forbids cancelling after a receipt', function () use ($makePo) {
        $po = $makePo([['product_id' => $this->product->id, 'ordered_qty' => 10, 'unit_cost' => 5]]);
        $po->update(['status' => PurchaseOrderStatusEnum::SENT]);

        // A sent order with no receipts can be cancelled.
        $this->patch(route('inventory.purchase-orders.cancel', $po))->assertRedirect();
        expect($po->refresh()->status)->toBe(PurchaseOrderStatusEnum::CANCELLED);

        // Now build a second order, receive part of it, and confirm cancel is blocked.
        $po2 = $makePo([['product_id' => $this->product->id, 'ordered_qty' => 10, 'unit_cost' => 5]]);
        $po2->update(['status' => PurchaseOrderStatusEnum::SENT]);
        $this->post(route('inventory.purchase-orders.receive', $po2), [
            'receipts' => [['line_id' => $po2->lines->first()->id, 'qty' => 3]],
        ])->assertRedirect();

        $this->patch(route('inventory.purchase-orders.cancel', $po2))->assertSessionHasErrors('status');
        expect($po2->refresh()->status)->toBe(PurchaseOrderStatusEnum::PARTIAL);
    });

    it('lets an accountant view but not create or receive purchase orders', function () use ($makePo) {
        $po = $makePo();
        $po->update(['status' => PurchaseOrderStatusEnum::SENT]);

        $accountant = User::factory()->create(['branch_id' => $this->branch->id]);
        $accountant->addRole(Roles::ACCOUNTANT->value);
        $this->actingAs($accountant);

        $this->get(route('inventory.purchase-orders.index'))->assertOk();

        $this->post(route('inventory.purchase-orders.store'), [
            'order_date' => now()->format('Y-m-d'),
            'lines' => [['product_id' => $this->product->id, 'ordered_qty' => 1, 'unit_cost' => 1]],
        ])->assertForbidden();

        $this->post(route('inventory.purchase-orders.receive', $po), [
            'receipts' => [['line_id' => $po->lines->first()->id, 'qty' => 1]],
        ])->assertForbidden();
    });
});
