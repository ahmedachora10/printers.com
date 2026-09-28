<?php

namespace App\Actions\InvoiceReturnRequest;

use App\Enums\ReturnRequestStatusEnum;
use App\Models\InvoiceReturnRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** تاسك 135 — الرفض لا يمسّ الفاتورة، وسببه يصل الموظف. */
class RejectInvoiceReturnRequestAction
{
    public function handle(InvoiceReturnRequest $request, User $actor, string $reason): InvoiceReturnRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason) {
            $request = $request->lockPending();

            $request->update([
                'status' => ReturnRequestStatusEnum::REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $request;
        });
    }
}
