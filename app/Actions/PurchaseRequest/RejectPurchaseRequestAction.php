<?php

namespace App\Actions\PurchaseRequest;

use App\Enums\PurchaseRequestStatusEnum;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectPurchaseRequestAction
{
    public function handle(PurchaseRequest $request, string $reason): PurchaseRequest
    {
        if (! $request->status->canDecide()) {
            throw ValidationException::withMessages([
                'status' => 'تم اتخاذ قرار في هذا الطلب مسبقاً.',
            ]);
        }

        return DB::transaction(function () use ($request, $reason) {
            $request->update([
                'status' => PurchaseRequestStatusEnum::REJECTED,
                'decided_by' => auth()->id(),
                'decided_at' => now(),
                'decision_reason' => $reason,
            ]);

            return $request;
        });
    }
}
