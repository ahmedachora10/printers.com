<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\Loyalty\EarnLoyaltyPointsAction;
use App\Actions\Loyalty\RedeemLoyaltyPointsAction;
use App\Actions\ServiceInvoice\Concerns\WritesServiceInvoiceLines;
use App\Enums\InvoiceStatusEnum;
use App\Models\Branch;
use App\Models\ServiceInvoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CreateServiceInvoiceAction
{
    use WritesServiceInvoiceLines;

    public function __construct(
        private readonly CalculateServiceInvoiceAction $calculator,
        private readonly RedeemLoyaltyPointsAction $redeemLoyaltyPoints,
        private readonly EarnLoyaltyPointsAction $earnLoyaltyPoints,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, ?UploadedFile $receipt = null): ServiceInvoice
    {
        $user = auth()->user();
        $branchId = $user->branchId;
        $branch = Branch::findOrFail($branchId);
        $vatPct = (float) $branch->vat_rate_override;

        return DB::transaction(function () use ($data, $user, $branchId, $vatPct, $receipt) {
            $calc = $this->calculator->handle($data, $user, $branchId, $vatPct);

            $status = InvoiceStatusEnum::from($data['status']);

            $invoice = ServiceInvoice::create([
                'invoice_number' => $this->generateInvoiceNumber($branchId),
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'status' => $status,
                'paid_at' => $status === InvoiceStatusEnum::PAID ? now() : null,
                ...$calc['attributes'],
            ]);

            if ($receipt !== null) {
                $invoice->addMedia($receipt)->toMediaCollection(ServiceInvoice::RECEIPT_COLLECTION);
            }

            $this->writeLinesAndLedger($invoice, $calc['lines'], $user->id, $branchId);

            if ($calc['coupon']) {
                $calc['coupon']->increment('used_count');
            }

            // Spend redeemed points (decrement + immutable ledger row), then
            // accrue earnings. Earning re-reads the post-redemption balance and
            // no-ops unless the invoice is paid for an eligible customer.
            if ($calc['pointsRedeemed'] > 0 && $invoice->customer_id !== null) {
                $this->redeemLoyaltyPoints->handle($invoice, $invoice->customer_id, $calc['pointsRedeemed']);
            }

            $this->earnLoyaltyPoints->handle($invoice);

            return $invoice;
        });
    }

    private function generateInvoiceNumber(int $branchId): string
    {
        $seq = ServiceInvoice::withTrashed()
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->count() + 1;

        return sprintf('SINV-%03d-%05d', $branchId, $seq);
    }
}
