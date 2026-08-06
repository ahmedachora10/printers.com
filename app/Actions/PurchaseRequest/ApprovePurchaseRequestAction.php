<?php

namespace App\Actions\PurchaseRequest;

use App\Enums\PurchaseRequestStatusEnum;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovePurchaseRequestAction
{
    public function handle(PurchaseRequest $request): PurchaseRequest
    {
        if (! $request->status->canDecide()) {
            throw ValidationException::withMessages([
                'status' => 'تم اتخاذ قرار في هذا الطلب مسبقاً.',
            ]);
        }

        return DB::transaction(function () use ($request) {
            $request->update([
                'status' => PurchaseRequestStatusEnum::APPROVED,
                'decided_by' => auth()->id(),
                'decided_at' => now(),
                'decision_reason' => null,
            ]);

            return $request;
        });
    }
}
