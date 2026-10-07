<?php

namespace App\Actions\ServiceInvoice\Concerns;

use App\Models\CustomerAddress;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceShipment;
use Illuminate\Validation\ValidationException;

/**
 * تاسك 170 — حفظ طلبات التوصيل كما حسبها CalculateServiceInvoiceAction.
 *
 * الطلب الذي يعود بمعرّفه يُحدَّث في مكانه، فتبقى تسوية سائقه (تاسك 111) مربوطةً
 * به عبر التعديل؛ وما لم يعد يُحذف — إلا طلباً سُوّي مع سائقه: تلك مالٌ خرج
 * من الصندوق، فتُلغى التسوية أولاً من كشف التوصيل.
 */
trait SyncsServiceInvoiceShipments
{
    /** @param list<array<string, mixed>> $shipments */
    protected function syncShipments(ServiceInvoice $invoice, array $shipments): void
    {
        $existing = $invoice->shipments()->with('settlement')->get();
        $kept = [];

        foreach ($shipments as $shipment) {
            $attributes = collect($shipment)->only([
                'provider_id', 'zone_id', 'distance_km', 'customer_address_id', 'address', 'fee',
            ])->all();

            // معرّفٌ لا يخصّ هذه الفاتورة يُعامل طلباً جديداً.
            $row = $existing->find($shipment['id'] ?? 0);
            $row ? $row->update($attributes) : $row = $invoice->shipments()->create($attributes);
            $kept[] = $row->id;

            $this->saveShippingAddress($invoice, $row, $shipment);
        }

        $removed = $existing->except($kept);

        if ($removed->contains(fn (ServiceInvoiceShipment $s) => $s->settlement !== null)) {
            throw ValidationException::withMessages([
                'shipments' => 'طلب توصيلٍ سُوّي مع سائقه لا يُحذف — ألغِ التسوية من كشف التوصيل أولاً.',
            ]);
        }

        // Eloquent's except() matches model keys but re-indexes — so modelKeys(), not keys().
        ServiceInvoiceShipment::query()->whereKey($removed->modelKeys())->delete();
        $invoice->unsetRelation('shipments');
    }

    /**
     * تاسك 93 — حفظ عنوان الطلب الجديد في دفتر العميل.
     *
     * **إضافةٌ لا استبدال**: عميلٌ طلب توصيلاً لمكتبه مرّةً لا يُدهس عنوان منزله
     * المحفوظ منذ سنة. ولا يُكتب صفٌّ إلا حين طلب الكاشير ذلك صراحةً، ولعميلٍ له
     * بطاقة: العميل العابر عنوانه لقطةٌ على الطلب وحده. ولقطة `address` على
     * الطلب تبقى هي المطبوعة مهما عُدّل الدفتر بعدها.
     *
     * @param  array<string, mixed>  $shipment
     */
    private function saveShippingAddress(ServiceInvoice $invoice, ServiceInvoiceShipment $row, array $shipment): void
    {
        // العنوان المختار من الدفتر أصلاً لا يُنسخ صفّاً ثانياً.
        if (! $shipment['save_address'] || $invoice->customer_id === null || $row->customer_address_id !== null) {
            return;
        }

        $address = trim((string) ($row->address ?? ''));

        if ($address === '') {
            return;
        }

        // العنوان نفسه مرّتين لا يصير صفّين: الكاشير قد يعيد كتابته حرفياً.
        $saved = CustomerAddress::query()
            ->where('customer_id', $invoice->customer_id)
            ->where('address', $address)
            ->first();

        $saved ??= CustomerAddress::create([
            'customer_id' => $invoice->customer_id,
            'label' => $this->trimmedOrNull($shipment['address_label']),
            'address' => $address,
            'location_url' => $this->trimmedOrNull($shipment['location_url']),
            'delivery_zone_id' => $row->zone_id,
            // أوّل عنوانٍ للعميل هو افتراضيّه؛ وما بعده لا يزيح افتراضياً قائماً.
            'is_default' => ! CustomerAddress::query()->where('customer_id', $invoice->customer_id)->exists(),
        ]);

        $row->update(['customer_address_id' => $saved->id]);
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
