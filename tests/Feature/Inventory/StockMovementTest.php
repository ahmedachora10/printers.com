<?php

use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\Roles;
use App\Enums\StockMovementTypeEnum;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Stock Movements', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->addRole(Roles::BRANCH_ADMIN->value);

        $this->branch = Branch::factory()->create(['owner_id' => $this->admin->id]);
        $this->admin->update(['branch_id' => $this->branch->id]);
        $this->actingAs($this->admin);

        $this->category = ProductCategory::factory()->create();
        $this->unit = ProductUnit::factory()->create();

        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'current_stock' => 0,
        ]);
    });

    it('recomputes current_stock as the signed sum of the ledger', function () {
        $action = app(RecordStockMovementAction::class);

        $action->handle($this->product, StockMovementTypeEnum::OPENING_STOCK, 100);
        expect($this->product->refresh()->current_stock)->toBe(100);

        $action->handle($this->product, StockMovementTypeEnum::SALE_OUT, 30);
        expect($this->product->refresh()->current_stock)->toBe(70);

        $action->handle($this->product, StockMovementTypeEnum::RETURN_IN, 5);
        expect($this->product->refresh()->current_stock)->toBe(75);
    });

    it('stores outbound quantities as negative and inbound as positive', function () {
        $action = app(RecordStockMovementAction::class);

        $in = $action->handle($this->product, StockMovementTypeEnum::PURCHASE_IN, 40);
        $out = $action->handle($this->product, StockMovementTypeEnum::ADJUSTMENT_OUT, 10);

        expect($in->qty)->toBe(40)
            ->and($out->qty)->toBe(-10);
    });

    it('defaults created_by to the authenticated user', function () {
        $movement = app(RecordStockMovementAction::class)
            ->handle($this->product, StockMovementTypeEnum::OPENING_STOCK, 10);

        expect($movement->created_by)->toBe($this->admin->id);
    });

    it('forbids updating a stock movement', function () {
        $movement = StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
        ]);

        expect(fn () => $movement->update(['qty' => 999]))
            ->toThrow(RuntimeException::class);
    });

    it('forbids deleting a stock movement', function () {
        $movement = StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
        ]);

        expect(fn () => $movement->delete())
            ->toThrow(RuntimeException::class);
    });

    it('does not maintain an updated_at column', function () {
        $movement = StockMovement::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
        ]);

        expect($movement->updated_at)->toBeNull();
    });
});
