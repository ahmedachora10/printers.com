<?php

namespace App\Actions\PurchaseRequest;

use App\Enums\PurchaseRequestStatusEnum;
use App\Models\Product;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;

class CreatePurchaseRequestAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): PurchaseRequest
    {
        return DB::transaction(function () use ($data) {
            $request = PurchaseRequest::create([
                // branch_id is resolved in StorePurchaseRequestRequest (own
                // branch for non super-admins, chosen branch for super-admin).
                'branch_id' => $data['branch_id'],
                'requested_by' => auth()->id(),
                'status' => PurchaseRequestStatusEnum::PENDING,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $productId = $line['product_id'] ?? null;

                $request->lines()->create([
                    'product_id' => $productId,
                    // A known product names itself; free-text items carry the
                    // name the requester typed.
                    'item_name' => $productId
                        ? (Product::find($productId)?->name ?? $line['item_name'])
                        : $line['item_name'],
                    'qty' => $line['qty'],
                    'estimated_unit_cost' => $line['estimated_unit_cost'] ?? null,
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $request;
        });
    }
}
