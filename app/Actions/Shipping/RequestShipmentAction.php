<?php

namespace App\Actions\Shipping;

use App\Actions\ServiceInvoice\CalculateServiceInvoiceAction;
use App\Actions\ServiceInvoice\Concerns\SyncsServiceInvoiceShipments;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceShipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * توصيلٌ يطلبه العميل بعد اعتماد فاتورته («كنت سآخذها بنفسي، أرسلوها»). يُحفظ
 * طلباً منتظراً لا يمسّ الفاتورة حتى يعتمده المحاسب (ApproveShipmentAction).
 *
 * القيمة تُحسم بقواعد نقطة البيع نفسها: الموظف يأخذ سعر الشريحة، والإدارة تكتب.
 */
class RequestShipmentAction
{
    use SyncsServiceInvoiceShipments;

    public function __construct(private readonly CalculateServiceInvoiceAction $calculator) {}

    /** @param array<string, mixed> $data طلبٌ واحد بحقول `shipments.*` */
    public function handle(ServiceInvoice $invoice, array $data, User $actor): ServiceInvoiceShipment
    {
        $shipment = $this->calculator->resolveShipment($data, 'shipments.0', (int) $invoice->branch_id, $invoice->customer_id);

        // كشف التوصيل والتسوية يدوران على السائق، فلا توصيل بلا سائق.
        if ($shipment === null || $shipment['provider_id'] === null) {
            throw ValidationException::withMessages(['shipments.0.provider_id' => 'اختر السائق أو شركة التوصيل.']);
        }

        return DB::transaction(function () use ($invoice, $shipment, $actor) {
            $row = $invoice->pendingShipments()->create([
                ...collect($shipment)->only(['provider_id', 'zone_id', 'distance_km', 'customer_address_id', 'address', 'fee'])->all(),
                'requested_by' => $actor->id,
            ]);

            $this->saveShippingAddress($invoice, $row, $shipment);

            return $row;
        });
    }
}
