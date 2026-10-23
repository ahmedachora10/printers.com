<?php

namespace App\Actions\Quotation;

use App\Actions\ProductInvoice\CreateProductInvoiceAction;
use App\Actions\ServiceInvoice\CalculateServiceInvoiceAction;
use App\Enums\InvoiceTypeEnum;
use App\Enums\QuotationStatusEnum;
use App\Models\Branch;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * يحسب العرض بحاسبة فاتورته نفسها ويحفظ اللقطة — بلا أي أثر مالي: لا كوبون
 * يُستهلك ولا نقاط ولا عمولة ولا خامات ولا حركة مخزون. الأثر الوحيد عميلٌ
 * عابر يُسجَّل باسمه وجواله، كما تفعل الحاسبة لأي فاتورة.
 */
class SaveQuotationAction
{
    public function __construct(
        private readonly CalculateServiceInvoiceAction $serviceCalculator,
        private readonly CreateProductInvoiceAction $productInvoices,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(InvoiceTypeEnum $type, array $data, User $user): Quotation
    {
        $branchId = (int) $user->branchId;

        return DB::transaction(fn () => Quotation::create([
            'quotation_number' => Quotation::nextNumber($branchId),
            'type' => $type,
            'branch_id' => $branchId,
            'user_id' => $user->id,
            'status' => QuotationStatusEnum::NEW,
            ...$this->snapshot($type, $data, $user, $branchId),
        ]));
    }

    /**
     * التعديل يُبقي الحالة، إلا المرفوض فيعود «جديداً» — عرضٌ معدَّل يُرسل
     * للعميل من جديد.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Quotation $quotation, array $data): Quotation
    {
        return DB::transaction(function () use ($quotation, $data) {
            $quotation->update([
                ...$this->snapshot($quotation->type, $data, $quotation->user, $quotation->branch_id),
                ...($quotation->status === QuotationStatusEnum::REJECTED
                    ? ['status' => QuotationStatusEnum::NEW, 'rejection_reason' => null, 'decided_at' => null]
                    : []),
            ]);

            return $quotation;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function snapshot(InvoiceTypeEnum $type, array $data, User $user, int $branchId): array
    {
        $vatPct = (float) Branch::findOrFail($branchId)->vat_rate_override;

        $calc = $type === InvoiceTypeEnum::SERVICE
            ? $this->serviceCalculator->handle($data, $user, $branchId, $vatPct)
            : $this->productInvoices->calculate($data, $branchId, $vatPct, checkStock: false);

        $lines = array_map(fn (array $line) => array_key_exists('product', $line)
            ? [...Arr::except($line, 'product'), 'product_id' => $line['product']?->id]
            : Arr::except($line, 'materials_cost_authored'), $calc['lines']);

        return [
            'customer_id' => $calc['attributes']['customer_id'],
            'total_amount' => $calc['attributes']['total_amount'],
            'data' => [
                'attributes' => Arr::except($calc['attributes'], ['payment_method_id', 'internal_notes']),
                'lines' => $lines,
                'helpers' => $calc['helpers'] ?? [],
                'shipments' => $calc['shipments'] ?? [],
                'agent_ids' => array_map('intval', $data['agent_ids'] ?? []),
            ],
        ];
    }
}
