<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\Loyalty\EarnLoyaltyPointsAction;
use App\Actions\Loyalty\RedeemLoyaltyPointsAction;
use App\Actions\ServiceInvoice\Concerns\LogsAuthoredMaterialsCost;
use App\Actions\ServiceInvoice\Concerns\SyncsServiceInvoiceAgents;
use App\Actions\ServiceInvoice\Concerns\WritesServiceInvoiceLines;
use App\Enums\InvoiceStatusEnum;
use App\Models\Branch;
use App\Models\ServiceInvoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CreateServiceInvoiceAction
{
    use LogsAuthoredMaterialsCost, SyncsServiceInvoiceAgents, WritesServiceInvoiceLines;

    public function __construct(
        private readonly CalculateServiceInvoiceAction $calculator,
        private readonly RedeemLoyaltyPointsAction $redeemLoyaltyPoints,
        private readonly EarnLoyaltyPointsAction $earnLoyaltyPoints,
        private readonly ConsumeServiceMaterialsAction $consumeMaterials,
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

            $this->writeLines($invoice, $calc['lines']);
            $this->logAuthoredMaterialsCost($invoice, $calc['lines']);

            // Commission is earned only once the invoice is approved (paid). A due
            // invoice — every employee-raised one — writes no ledger row yet; it is
            // written when the accountant settles it (MarkServiceInvoicePaidAction).
            // خامات المخزون تتبع القاعدة نفسها (تاسك 50): فاتورةٌ تُنشأ مدفوعة
            // تخصمها فوراً، والآجلة تنتظر اعتمادها.
            if ($status === InvoiceStatusEnum::PAID) {
                $this->writeLedgerFromLines($invoice, $user->id, $branchId);
                $this->consumeMaterials->consume($invoice, (int) $user->id);
            }

            $this->syncInvoiceAgents($invoice, $calc['agents']);

            if ($calc['coupon']) {
                $calc['coupon']->increment('used_count');
            }

            // النقاط تتبع القاعدة نفسها: لا تُخصم من رصيد العميل إلا عند اعتماد
            // الفاتورة. فاتورةٌ تُنشأ مدفوعة تُخصم نقاطها الآن، والآجلة تبقى نقاطها
            // محجوزةً على الفاتورة (points_redeemed بلا ختم) حتى يعتمدها المحاسب.
            // ثم يأتي الاكتساب، وهو لا يقع أصلاً إلا على فاتورة مدفوعة.
            if ($status === InvoiceStatusEnum::PAID) {
                $this->redeemLoyaltyPoints->handle($invoice);
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
