<?php

namespace App\Actions\Commission;

use App\Models\CommissionLedger;
use App\Models\CommissionPayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayCommissionAction
{
    /**
     * Settle a user's unpaid commission for the given period: record an
     * (immutable) commission_payments row and stamp paid_at on the covered
     * ledger entries. All-or-nothing within a single transaction.
     *
     * @param  array{user_id: int, period_start: string, period_end: string, notes?: string|null}  $data
     */
    public function handle(array $data, User $paidBy): CommissionPayment
    {
        $periodStart = Carbon::parse($data['period_start'])->startOfDay();
        $periodEnd = Carbon::parse($data['period_end'])->endOfDay();

        return DB::transaction(function () use ($data, $paidBy, $periodStart, $periodEnd) {
            $employee = User::query()->findOrFail($data['user_id']);

            $unpaid = CommissionLedger::query()
                ->where('user_id', $employee->id)
                ->whereNull('paid_at')
                ->whereBetween('earned_at', [$periodStart, $periodEnd])
                ->lockForUpdate();

            $total = (float) $unpaid->clone()->sum('amount');

            if ($total <= 0) {
                throw ValidationException::withMessages([
                    'period_start' => 'لا توجد عمولات مستحقة للصرف خلال هذه الفترة.',
                ]);
            }

            $paidAt = now();

            $payment = CommissionPayment::create([
                'user_id' => $employee->id,
                'branch_id' => $employee->branch_id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'total_amount' => $total,
                'paid_by' => $paidBy->id,
                'paid_at' => $paidAt,
                'notes' => $data['notes'] ?? null,
            ]);

            $unpaid->clone()->update(['paid_at' => $paidAt]);

            return $payment;
        });
    }
}
