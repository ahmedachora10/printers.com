<?php

namespace App\Actions\ServiceInvoice\Concerns;

use App\Models\CustomerAddress;
use App\Models\ServiceInvoice;

/**
 * تاسك 93 — حفظ عنوان التوصيل الجديد في دفتر العميل.
 *
 * **إضافةٌ لا استبدال**: عميلٌ طلب توصيلاً لمكتبه مرّةً لا يُدهس عنوان منزله
 * المحفوظ منذ سنة. وهو سبب اختيار الدفتر على حقلٍ واحد من أساسه.
 *
 * ولا يُكتب صفٌّ إلا حين طلب الكاشير ذلك صراحةً (`save_shipping_address`)،
 * ولعميلٍ له بطاقة: العميل العابر عنوانه لقطةٌ على الفاتورة وحدها.
 *
 * ولقطة `shipping_address` على الفاتورة تبقى هي المطبوعة مهما عُدّل الدفتر بعدها.
 */
trait SavesShippingAddress
{
    /** @param array<string, mixed> $data */
    protected function saveShippingAddress(ServiceInvoice $invoice, array $data): void
    {
        if (! ($data['save_shipping_address'] ?? false)) {
            return;
        }

        // العنوان المختار من الدفتر أصلاً لا يُنسخ صفّاً ثانياً.
        if ($invoice->customer_id === null || $invoice->customer_address_id !== null) {
            return;
        }

        $address = trim((string) ($invoice->shipping_address ?? ''));

        if ($address === '') {
            return;
        }

        // العنوان نفسه مرّتين لا يصير صفّين: الكاشير قد يعيد كتابته حرفياً.
        $existing = CustomerAddress::query()
            ->where('customer_id', $invoice->customer_id)
            ->where('address', $address)
            ->first();

        if ($existing !== null) {
            $invoice->update(['customer_address_id' => $existing->id]);

            return;
        }

        $isFirst = ! CustomerAddress::query()
            ->where('customer_id', $invoice->customer_id)
            ->exists();

        $saved = CustomerAddress::create([
            'customer_id' => $invoice->customer_id,
            'label' => $this->trimmedOrNull($data['shipping_address_label'] ?? null),
            'address' => $address,
            'location_url' => $this->trimmedOrNull($data['shipping_location_url'] ?? null),
            'delivery_zone_id' => $invoice->shipping_zone_id,
            // أوّل عنوانٍ للعميل هو افتراضيّه؛ وما بعده لا يزيح افتراضياً قائماً.
            'is_default' => $isFirst,
        ]);

        $invoice->update(['customer_address_id' => $saved->id]);
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
